<?php

namespace App\Services\Data;

use App\Http\Requests\LedgerRequest;
use App\Http\Requests\PartyRequest;
use App\Http\Requests\StockItemRequest;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AuditLog;
use App\Models\Godown;
use App\Models\Party;
use App\Models\StockCategory;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\Unit;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * CSV / Excel import of ledgers, customers, suppliers, stock items and
 * vouchers. Each row is validated by the same rules as the entry screens
 * (the form requests and the voucher engine). A preview runs the whole
 * import inside a transaction and rolls it back; the real import commits
 * only when every row is valid, so a file is imported completely or not at all.
 */
class ImportService
{
    public const MAX_ROWS = 5000;

    /** column key => [header, required, help] */
    public const TYPES = [
        'ledgers' => [
            'label' => 'Ledgers', 'permission' => 'masters.create',
            'columns' => [
                'name' => ['Name', true, ''], 'group' => ['Group', true, 'An existing account group, e.g. Indirect Expenses'],
                'alias' => ['Alias', false, ''], 'code' => ['Code', false, ''],
                'opening_balance' => ['Opening Balance', false, ''], 'dr_cr' => ['Dr/Cr', false, 'Dr or Cr'],
                'gst_rate' => ['GST Rate', false, 'For sales/purchase ledgers'], 'hsn_sac' => ['HSN/SAC', false, ''],
                'bank_name' => ['Bank Name', false, ''], 'bank_account_number' => ['Account Number', false, ''], 'ifsc' => ['IFSC', false, ''],
                'notes' => ['Notes', false, ''],
            ],
            'example' => ['Office Rent', 'Indirect Expenses', '', '', '0', 'Dr', '', '', '', '', '', ''],
        ],
        'customers' => [
            'label' => 'Customers', 'permission' => 'sales.create',
            'columns' => [
                'name' => ['Name', true, ''], 'group' => ['Group', false, 'Default Sundry Debtors'], 'gst_type' => ['GST Type', false, 'regular, unregistered, composition, consumer, overseas or sez'],
                'gstin' => ['GSTIN', false, ''], 'pan' => ['PAN', false, ''], 'state' => ['State', false, 'Required unless overseas'],
                'city' => ['City', false, ''], 'pincode' => ['PIN', false, ''], 'address' => ['Address', false, ''],
                'contact_person' => ['Contact Person', false, ''], 'phone' => ['Phone', false, ''], 'email' => ['Email', false, ''],
                'opening_balance' => ['Opening Balance', false, ''], 'dr_cr' => ['Dr/Cr', false, ''],
                'credit_days' => ['Credit Days', false, ''], 'credit_limit' => ['Credit Limit', false, ''],
            ],
            'example' => ['Mumbai Retail', '', 'regular', '27ABCDE1234F1Z5', '', 'Maharashtra', 'Mumbai', '400001', 'Shop 4, Main Road', 'Ravi', '9820000000', 'ravi@example.com', '5000', 'Dr', '30', '100000'],
        ],
        'suppliers' => [
            'label' => 'Suppliers', 'permission' => 'purchases.create',
            'columns' => 'customers',
            'example' => ['Pune Steel Supply', '', 'unregistered', '', '', 'Maharashtra', 'Pune', '411001', '', '', '', '', '0', 'Cr', '45', ''],
        ],
        'stock_items' => [
            'label' => 'Stock items', 'permission' => 'inventory.create',
            'columns' => [
                'name' => ['Name', true, ''], 'unit' => ['Unit', true, 'Symbol of an existing unit, e.g. Nos'],
                'group' => ['Stock Group', false, 'Created if missing'], 'category' => ['Category', false, 'Created if missing'],
                'code' => ['Code', false, ''], 'hsn_sac' => ['HSN/SAC', false, ''], 'gst_rate' => ['GST Rate', false, ''],
                'sales_rate' => ['Sales Rate', false, ''], 'purchase_rate' => ['Purchase Rate', false, ''],
                'opening_quantity' => ['Opening Qty', false, ''], 'opening_rate' => ['Opening Rate', false, ''], 'opening_value' => ['Opening Value', false, 'Or quantity × rate'],
                'godown' => ['Godown', false, 'Default godown if blank'], 'reorder_level' => ['Reorder Level', false, ''], 'description' => ['Description', false, ''],
            ],
            'example' => ['Steel Rod 12mm', 'Nos', 'Steel', '', 'SR12', '7214', '18', '450', '380', '100', '380', '', '', '20', ''],
        ],
        'vouchers' => [
            'label' => 'Vouchers (journal, payment, receipt, contra)', 'permission' => 'vouchers.post',
            'columns' => [
                'voucher' => ['Voucher', true, 'Rows with the same value form one voucher'], 'date' => ['Date', true, 'YYYY-MM-DD or DD-MM-YYYY'],
                'type' => ['Type', true, 'journal, payment, receipt or contra'], 'ledger' => ['Ledger', true, 'Existing ledger name'],
                'debit' => ['Debit', false, ''], 'credit' => ['Credit', false, ''], 'narration' => ['Narration', false, 'From the first row'],
                'reference' => ['Reference', false, ''],
            ],
            'example' => ['1', '2026-04-01', 'journal', 'Office Rent', '15000', '', 'Rent for April', ''],
        ],
    ];

    public function __construct(protected VoucherService $vouchers) {}

    public static function columns(string $type): array
    {
        $columns = self::TYPES[$type]['columns'];

        return is_string($columns) ? self::TYPES[$columns]['columns'] : $columns;
    }

    /**
     * Reads a CSV or XLSX file into rows keyed by column (row number => [key => value]).
     *
     * @return array<int, array<string, string>>
     */
    public function read(string $path, string $extension, string $type): array
    {
        $extension = strtolower($extension);
        if (in_array($extension, ['xlsx', 'xls', 'ods'], true)) {
            try {
                $sheet = IOFactory::load($path)->getActiveSheet();
            } catch (Throwable) {
                throw ValidationException::withMessages(['file' => 'The spreadsheet could not be read. Save it as .xlsx or .csv and try again.']);
            }
            $raw = $sheet->toArray(null, true, false, false);
        } else {
            $raw = [];
            $handle = fopen($path, 'r');
            $first = fgets($handle);
            $delimiter = substr_count((string) $first, ';') > substr_count((string) $first, ',') ? ';' : ',';
            rewind($handle);
            while (($line = fgetcsv($handle, null, $delimiter)) !== false) {
                $raw[] = $line;
            }
            fclose($handle);
        }

        $header = array_shift($raw) ?? [];
        $header[0] = isset($header[0]) ? preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) : null;
        $lookup = [];
        foreach (static::columns($type) as $key => [$label]) {
            $lookup[$this->normalise($label)] = $key;
            $lookup[$this->normalise($key)] = $key;
        }
        $map = [];
        foreach ($header as $i => $h) {
            $key = $lookup[$this->normalise((string) $h)] ?? null;
            if ($key) {
                $map[$i] = $key;
            }
        }
        $missing = array_diff(array_keys(array_filter(static::columns($type), fn ($c) => $c[1])), $map);
        if ($missing) {
            throw ValidationException::withMessages(['file' => 'Missing column(s): '.implode(', ', array_map(fn ($k) => static::columns($type)[$k][0], $missing)).'. Download the template for the expected headings.']);
        }

        $rows = [];
        foreach ($raw as $n => $line) {
            $row = [];
            foreach ($map as $i => $key) {
                $value = $line[$i] ?? null;
                if ($key === 'date' && is_numeric($value)) {
                    $value = ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
                }
                $row[$key] = is_float($value) ? rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') : trim((string) $value);
            }
            if (implode('', $row) === '') {
                continue;
            }
            $rows[$n + 2] = $row; // spreadsheet row number (header is row 1)
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'A file can have at most '.self::MAX_ROWS.' rows. Split it into smaller files.']);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'The file has no data rows.']);
        }

        return $rows;
    }

    /**
     * Validates and imports the rows. With $commit false (preview) nothing is saved.
     *
     * @return array{total:int, created:int, skipped:int, errors: list<array{row:int, message:string}>, skipped_rows: list<array{row:int, message:string}>}
     */
    public function run(string $type, array $rows, User $user, bool $commit, bool $skipDuplicates = false): array
    {
        $result = ['total' => count($rows), 'created' => 0, 'skipped' => 0, 'errors' => [], 'skipped_rows' => []];

        DB::beginTransaction();
        try {
            $groups = $type === 'vouchers' ? $this->voucherGroups($rows, $result) : array_map(fn ($r) => [$r], $rows);
            foreach ($groups as $first => $group) {
                $rowNumber = $first;
                try {
                    $duplicate = match ($type) {
                        'vouchers' => $this->importVoucher($group, $user, $skipDuplicates),
                        default => $this->importMaster($type, $group[0], $skipDuplicates),
                    };
                    if ($duplicate) {
                        $result['skipped']++;
                        $result['skipped_rows'][] = ['row' => $rowNumber, 'message' => $duplicate];
                    } else {
                        $result['created']++;
                    }
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        foreach ((array) $messages as $message) {
                            $result['errors'][] = ['row' => $rowNumber, 'message' => $this->label($type, $field).$message];
                        }
                    }
                }
            }

            if ($commit && ! $result['errors']) {
                AuditLog::record('data_imported', null, null, ['type' => $type, 'created' => $result['created'], 'skipped' => $result['skipped']],
                    'Imported '.$result['created'].' '.strtolower(self::TYPES[$type]['label']));
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        usort($result['errors'], fn ($a, $b) => $a['row'] <=> $b['row']);

        return $result;
    }

    /** Returns a reason when the row is a duplicate that was skipped, null when created. */
    protected function importMaster(string $type, array $row, bool $skipDuplicates): ?string
    {
        $name = $row['name'] ?? '';
        $table = $type === 'stock_items' ? 'stock_items' : 'chart_of_accounts';
        $existing = $name !== '' && DB::table($table)->where('company_id', app(\App\Support\CurrentCompany::class)->id())
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
        if ($existing) {
            if ($skipDuplicates) {
                return "\"{$name}\" already exists.";
            }
            throw ValidationException::withMessages(['name' => "\"{$name}\" already exists. Tick \"skip rows that already exist\" to leave it as it is."]);
        }

        match ($type) {
            'ledgers' => $this->importLedger($row),
            'customers', 'suppliers' => $this->importParty($type, $row, $skipDuplicates),
            'stock_items' => $this->importStockItem($row),
        };

        return null;
    }

    protected function importLedger(array $row): void
    {
        $group = $this->group($row['group'] ?? '');
        if ($group && ($group->isBelow('sundry_debtors') || $group->isBelow('sundry_creditors'))) {
            throw ValidationException::withMessages(['group' => 'Import customers and suppliers with their own import (it adds GST and address details).']);
        }
        $request = $this->formRequest(LedgerRequest::class, [
            'name' => $row['name'] ?? '', 'alias' => $row['alias'] ?? null, 'code' => $row['code'] ?? null,
            'account_group_id' => $group?->id, 'opening_balance' => $this->amount($row['opening_balance'] ?? ''),
            'opening_balance_type' => $this->side($row['dr_cr'] ?? '', $group?->nature === 'liability' || $group?->nature === 'income' ? 'Cr' : 'Dr'),
            'gst_rate' => $this->blank($row['gst_rate'] ?? null), 'hsn_sac' => $this->blank($row['hsn_sac'] ?? null),
            'bank_name' => $this->blank($row['bank_name'] ?? null), 'bank_account_number' => $this->blank($row['bank_account_number'] ?? null),
            'ifsc' => $this->blank($row['ifsc'] ?? null), 'notes' => $this->blank($row['notes'] ?? null), 'is_active' => '1',
        ], [], ['group' => 'account_group_id']);
        Account::create($request->ledgerData());
    }

    protected function importParty(string $type, array $row, bool $skipDuplicates): void
    {
        $kind = $type === 'suppliers' ? 'supplier' : 'customer';
        $groupKey = $kind === 'supplier' ? 'sundry_creditors' : 'sundry_debtors';
        $group = ($row['group'] ?? '') !== '' ? $this->group($row['group']) : AccountGroup::query()->find(AccountGroup::idFor($groupKey));
        $gstin = strtoupper($row['gstin'] ?? '');
        $gstType = strtolower($row['gst_type'] ?? '') ?: ($gstin !== '' ? 'regular' : 'unregistered');

        if ($gstin !== '' && ($other = Party::query()->where('gstin', $gstin)->with('account')->first())) {
            throw ValidationException::withMessages(['gstin' => "GSTIN {$gstin} already belongs to {$other->account?->name}."]);
        }

        $request = $this->formRequest(PartyRequest::class, [
            'name' => $row['name'] ?? '', 'account_group_id' => $group?->id,
            'opening_balance' => $this->amount($row['opening_balance'] ?? ''), 'opening_balance_type' => $this->side($row['dr_cr'] ?? '', $kind === 'supplier' ? 'Cr' : 'Dr'),
            'maintain_bill_wise' => '1', 'credit_period_days' => $this->blank($row['credit_days'] ?? null), 'credit_limit' => $this->blank($this->amount($row['credit_limit'] ?? '')),
            'is_active' => '1', 'gst_registration_type' => $gstType, 'gstin' => $gstin ?: null, 'pan' => $this->blank($row['pan'] ?? null),
            'contact_person' => $this->blank($row['contact_person'] ?? null), 'phone' => $this->blank($row['phone'] ?? null), 'email' => $this->blank($row['email'] ?? null),
            'billing_address' => $this->blank($row['address'] ?? null), 'city' => $this->blank($row['city'] ?? null),
            'state' => $this->state($row['state'] ?? ''), 'pincode' => $this->blank($row['pincode'] ?? null),
        ], ['kind' => $kind], ['group' => 'account_group_id', 'gst_type' => 'gst_registration_type', 'address' => 'billing_address', 'credit_days' => 'credit_period_days']);

        $ledger = Account::create($request->ledgerData());
        $ledger->partyDetails()->create($request->partyData());
    }

    protected function importStockItem(array $row): void
    {
        $unitName = $row['unit'] ?? '';
        $unit = $unitName === '' ? null : Unit::query()->whereRaw('LOWER(symbol) = ?', [mb_strtolower($unitName)])->first()
            ?? Unit::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($unitName)])->first();
        if ($unitName !== '' && ! $unit) {
            throw ValidationException::withMessages(['unit' => "Unit \"{$unitName}\" does not exist. Add it under Units first."]);
        }
        $group = ($row['group'] ?? '') !== '' ? StockGroup::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($row['group'])])->first() ?? StockGroup::create(['name' => $row['group']]) : null;
        $category = ($row['category'] ?? '') !== '' ? StockCategory::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($row['category'])])->first() ?? StockCategory::create(['name' => $row['category']]) : null;
        $godown = null;
        if (($row['godown'] ?? '') !== '') {
            $godown = Godown::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($row['godown'])])->first();
            if (! $godown) {
                throw ValidationException::withMessages(['godown' => "Godown \"{$row['godown']}\" does not exist."]);
            }
        }
        $godown ??= Godown::query()->where('is_default', true)->first();

        $request = $this->formRequest(StockItemRequest::class, [
            'name' => $row['name'] ?? '', 'code' => $row['code'] ?? null, 'unit_id' => $unit?->id,
            'stock_group_id' => $group?->id, 'stock_category_id' => $category?->id,
            'hsn_sac' => $row['hsn_sac'] ?? null, 'gst_rate' => $row['gst_rate'] ?? null,
            'sales_rate' => $row['sales_rate'] ?? null, 'purchase_rate' => $row['purchase_rate'] ?? null,
            'opening_quantity' => $row['opening_quantity'] ?? null, 'opening_rate' => $row['opening_rate'] ?? null, 'opening_value' => $row['opening_value'] ?? null,
            'opening_godown_id' => $godown?->id, 'reorder_level' => $row['reorder_level'] ?? null, 'description' => $this->blank($row['description'] ?? null), 'is_active' => '1',
        ], [], ['unit' => 'unit_id', 'godown' => 'opening_godown_id']);
        StockItem::create($request->itemData());
    }

    /** Groups voucher rows by their "Voucher" key, keeping the first row number of each. */
    protected function voucherGroups(array $rows, array &$result): array
    {
        $groups = [];
        $firstRow = [];
        foreach ($rows as $n => $row) {
            $key = $row['voucher'] ?? '';
            if ($key === '') {
                $result['errors'][] = ['row' => $n, 'message' => 'Voucher: enter a voucher number or key to group the lines.'];

                continue;
            }
            $firstRow[$key] ??= $n;
            $groups[$firstRow[$key]][] = $row + ['_row' => $n];
        }
        $result['total'] = count($groups);

        return $groups;
    }

    protected function importVoucher(array $lines, User $user, bool $skipDuplicates): ?string
    {
        $head = $lines[0];
        $base = strtolower(trim($head['type'] ?? ''));
        $type = in_array($base, ['journal', 'payment', 'receipt', 'contra'], true)
            ? VoucherType::forBase($base)
            : VoucherType::query()->whereRaw('LOWER(name) = ?', [$base])->whereIn('base_type', ['journal', 'payment', 'receipt', 'contra'])->first();
        if (! $type) {
            throw ValidationException::withMessages(['type' => 'Use journal, payment, receipt or contra. Sales and purchases are imported as invoices, not vouchers.']);
        }
        $date = $this->date($head['date'] ?? '');
        if (! $date) {
            throw ValidationException::withMessages(['date' => 'Enter the date as YYYY-MM-DD or DD-MM-YYYY.']);
        }

        $entries = [];
        $errors = [];
        foreach ($lines as $line) {
            $name = $line['ledger'] ?? '';
            $account = Account::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if (! $account) {
                $errors["ledger_{$line['_row']}"] = "Row {$line['_row']}: ledger \"{$name}\" does not exist.";

                continue;
            }
            $debit = $this->amount($line['debit'] ?? '');
            $credit = $this->amount($line['credit'] ?? '');
            if ((float) $debit > 0 === (float) $credit > 0) {
                $errors["amount_{$line['_row']}"] = "Row {$line['_row']}: enter either a debit or a credit amount.";

                continue;
            }
            $entries[] = ['account_id' => $account->id, 'side' => (float) $debit > 0 ? 'Dr' : 'Cr', 'amount' => (float) $debit > 0 ? $debit : $credit];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        // The same voucher imported twice: same type, date, reference and the same lines.
        $total = array_sum(array_map(fn ($e) => $e['side'] === 'Dr' ? Money::toPaise($e['amount']) : 0, $entries));
        $candidates = Voucher::query()->where('voucher_type_id', $type->id)->whereDate('date', $date)->where('status', '!=', 'cancelled')
            ->when(($head['reference'] ?? '') !== '', fn ($q) => $q->where('reference', $head['reference']))->get()
            ->filter(fn ($v) => Money::toPaise($v->total) === $total);
        $signature = collect($entries)->map(fn ($e) => $e['account_id'].$e['side'].Money::toPaise($e['amount']))->sort()->values()->all();
        foreach ($candidates as $v) {
            $theirs = VoucherEntry::query()->where('voucher_id', $v->id)->get()
                ->map(fn ($e) => $e->account_id.((float) $e->debit > 0 ? 'Dr' : 'Cr').Money::toPaise((float) $e->debit > 0 ? $e->debit : $e->credit))->sort()->values()->all();
            if ($theirs === $signature) {
                if ($skipDuplicates) {
                    return "Same as voucher {$v->voucher_number} already entered.";
                }
                throw ValidationException::withMessages(['voucher' => "This voucher looks like {$v->voucher_number}, already entered on the same date with the same lines."]);
            }
        }

        $this->vouchers->create([
            'voucher_type_id' => $type->id, 'date' => $date, 'narration' => $this->blank($head['narration'] ?? null) ?? 'Imported',
            'reference' => $this->blank($head['reference'] ?? null), 'entries' => $entries,
        ], $user, Voucher::POSTED);

        return null;
    }

    /** Validates input with a form request as if it came from its screen. */
    protected function formRequest(string $class, array $input, array $route, array $rename = []): FormRequest
    {
        /** @var FormRequest $request */
        $request = $class::create('/import', 'POST', $input);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->setUserResolver(fn () => auth()->user());
        $request->setRouteResolver(fn () => new class($route)
        {
            public function __construct(public array $params) {}

            public function parameter($key, $default = null)
            {
                return $this->params[$key] ?? $default;
            }
        });
        try {
            $request->validateResolved();
        } catch (ValidationException $e) {
            $flip = array_flip($rename);
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [$flip[$k] ?? $k => $m])->all());
        }

        return $request;
    }

    protected function label(string $type, string $field): string
    {
        $columns = static::columns($type);
        if (isset($columns[$field])) {
            return $columns[$field][0].': ';
        }

        return str_starts_with($field, 'ledger_') || str_starts_with($field, 'amount_') ? '' : Str::headline($field).': ';
    }

    protected function group(string $name): ?AccountGroup
    {
        if ($name === '') {
            return null;
        }
        $group = AccountGroup::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->first();
        if (! $group) {
            throw ValidationException::withMessages(['group' => "Group \"{$name}\" does not exist."]);
        }

        return $group;
    }

    protected function state(string $state): ?string
    {
        if ($state === '') {
            return null;
        }
        foreach (config('india.states') as $code => $name) {
            if (mb_strtolower($name) === mb_strtolower(trim($state)) || (is_numeric($state) && (int) $state === (int) $code)) {
                return $name;
            }
        }

        return $state; // left for the validator to reject
    }

    protected function date(string $value): ?string
    {
        $value = trim($value);
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'Y/m/d'] as $format) {
            $d = \DateTime::createFromFormat('!'.$format, $value);
            if ($d && ! \DateTime::getLastErrors()) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    protected function side(string $value, string $default): string
    {
        $v = strtolower(trim($value));

        return match (true) {
            in_array($v, ['dr', 'debit', 'd'], true) => 'Dr',
            in_array($v, ['cr', 'credit', 'c'], true) => 'Cr',
            default => $default,
        };
    }

    protected function amount(string $value): string
    {
        return str_replace([',', '₹', ' '], '', trim($value));
    }

    protected function blank(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    protected function normalise(string $header): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($header));
    }
}
