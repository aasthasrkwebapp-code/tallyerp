<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\SerialNumber;
use App\Models\Voucher;
use App\Support\Books;
use App\Support\EInvoice;
use App\Support\EWayBill;
use App\Support\GstStates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sales and purchase vouchers in invoice mode: item lines with HSN and rate,
 * with GST split into CGST + SGST (same state) or IGST (other state).
 */
class GstInvoiceController extends Controller
{
    public const RATES = [0, 0.25, 3, 5, 12, 18, 28];

    /**
     * How each GST voucher type posts:
     *  side       - 'sales' uses sales ledgers and output tax; 'purchase' purchase ledgers and input tax
     *  partyDebit - whether the party is debited (they owe us more / we owe them less)
     *  stockOut   - whether stock leaves (sale, purchase return) or comes in
     * A credit note (sales return) reverses a sale; a debit note (purchase return) reverses a purchase.
     */
    public const KINDS = [
        'sales' => ['side' => 'sales', 'partyDebit' => true, 'stockOut' => true],
        'credit_note' => ['side' => 'sales', 'partyDebit' => false, 'stockOut' => false],
        'purchase' => ['side' => 'purchase', 'partyDebit' => false, 'stockOut' => false],
        'debit_note' => ['side' => 'purchase', 'partyDebit' => true, 'stockOut' => true],
    ];

    /**
     * Primary groups whose ledgers can be used on invoice lines, per side.
     * Purchase bills may be for goods, expenses or fixed assets.
     */
    public const LINE_GROUPS = [
        'sales' => ['SALES_ACCOUNTS'],
        'purchase' => ['PURCHASE_ACCOUNTS', 'DIRECT_EXPENSES', 'INDIRECT_EXPENSES', 'FIXED_ASSETS'],
    ];

    public function create(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $type = array_key_exists($request->query('type'), self::KINDS) ? $request->query('type') : 'sales';
        $itemGroups = self::LINE_GROUPS[self::KINDS[$type]['side']];

        // Converting an estimate/order/challan: start from its party and lines.
        $fromDocument = null;
        if ($request->filled('from_document') && ! $request->session()->hasOldInput()) {
            $fromDocument = $company->businessDocuments()->with('lines')->where('status', 'open')->findOrFail($request->integer('from_document'));
            $request->session()->now('_old_input', [
                'party_account_id' => $fromDocument->party_account_id,
                'place_of_supply' => $fromDocument->place_of_supply,
                'reference' => $fromDocument->typeLabel().' '.$fromDocument->numberLabel(),
                'narration' => $fromDocument->notes,
                'document_id' => $fromDocument->id,
                'lines' => $fromDocument->lines->map(fn ($l) => [
                    'account_id' => $l->account_id,
                    'stock_item_id' => $l->stock_item_id,
                    'description' => $l->description,
                    'hsn_sac' => $l->hsn_sac,
                    'quantity' => $l->quantity !== null ? (float) $l->quantity : '',
                    'item_rate' => $l->item_rate !== null ? (float) $l->item_rate : '',
                    'taxable' => (float) ($l->gross_amount ?? $l->taxable),
                    'discount_percent' => (float) $l->discount_percent ?: '',
                    'rate' => (string) (float) $l->rate,
                ])->all(),
            ]);
        }

        $accounts = $company->accounts()->where('is_active', true)->with('group')->orderBy('name')->get();
        $books = new Books($company);

        return view('gst-invoices.create', [
            'company' => $company,
            'type' => $type,
            'parties' => $accounts->filter(fn (Account $a) => in_array(
                $books->primaryGroup($a->account_group_id)->code,
                ['CURRENT_ASSETS', 'CURRENT_LIABILITIES'],
                true,
            ) && ! str_starts_with((string) $a->code, 'GST_')),
            'items' => $accounts->filter(fn (Account $a) => in_array($books->primaryGroup($a->account_group_id)->code, $itemGroups, true)),
            'stockItems' => $company->stockItems()->with('unit')->orderBy('name')->get(),
            'batches' => $company->stockBatches()->orderBy('expiry_date')->get()
                ->map(fn ($b) => ['item' => $b->stock_item_id, 'no' => $b->batch_no, 'expiry' => $b->expiry_date?->format('d-m-Y'), 'left' => $b->balance()])
                ->values(),
            'rates' => self::RATES,
            'states' => GstStates::STATES,
        ]);
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        [$start, $end] = (new Books($company))->financialYear();
        $inCompany = fn () => Rule::exists('chart_of_accounts', 'id')->where('company_id', $company->id);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(self::KINDS))],
            'document_id' => ['nullable', Rule::exists('business_documents', 'id')->where('company_id', $company->id)->where('status', 'open')],
            'date' => ['required', 'date', 'after_or_equal:'.$start->toDateString(), 'before_or_equal:'.$end->toDateString()],
            'reference' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'party_account_id' => ['required', $inCompany()],
            'place_of_supply' => ['nullable', Rule::in(array_keys(GstStates::STATES))],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', $inCompany()],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.hsn_sac' => ['nullable', 'string', 'max:10'],
            'lines.*.stock_item_id' => ['nullable', Rule::exists('stock_items', 'id')->where('company_id', $company->id)],
            'lines.*.quantity' => ['nullable', 'required_with:lines.*.stock_item_id', 'numeric', 'gt:0', 'max:9999999999.999'],
            'lines.*.item_rate' => ['nullable', 'required_with:lines.*.stock_item_id', 'numeric', 'min:0', 'max:9999999999999.99'],
            'lines.*.taxable' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'lines.*.rate' => ['required', Rule::in(self::RATES)],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bill_discount_percent' => ['nullable', 'numeric', 'min:0', 'lt:100'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:40'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.serial_numbers' => ['nullable', 'string', 'max:5000'],
        ], [
            'date.after_or_equal' => 'The date must fall within financial year '.$company->financial_year.'.',
            'date.before_or_equal' => 'The date must fall within financial year '.$company->financial_year.'.',
        ]);

        $companyState = $company->stateCode();
        if (! $companyState) {
            throw ValidationException::withMessages([
                'party_account_id' => 'Set the company GSTIN or state before recording GST invoices.',
            ]);
        }

        $books = new Books($company);
        $kind = self::KINDS[$data['type']];
        $itemGroups = self::LINE_GROUPS[$kind['side']];
        foreach ($data['lines'] as $i => $line) {
            if (empty($line['stock_item_id']) && empty($line['taxable'])) {
                throw ValidationException::withMessages([
                    "lines.$i.taxable" => 'Line '.($i + 1).' needs a taxable value or a stock item with quantity and rate.',
                ]);
            }

            $account = $company->accounts()->find($line['account_id']);
            if (! in_array($books->primaryGroup($account->account_group_id)->code, $itemGroups, true)) {
                throw ValidationException::withMessages([
                    "lines.$i.account_id" => 'Line '.($i + 1).' must use a '.($kind['side'] === 'sales' ? 'sales' : 'purchase, expense or fixed asset').' ledger.',
                ]);
            }
        }

        $this->checkBatches($company, $data['lines'], $kind['stockOut']);
        $serials = $this->checkSerials($company, $data['lines'], $data['type']);

        $party = $company->accounts()->findOrFail($data['party_account_id']);
        $placeOfSupply = $data['place_of_supply']
            ?? $party->state_code
            ?? GstStates::codeFor($party->gstin)
            ?? $companyState;
        $interState = $placeOfSupply !== $companyState;

        // Purchases to a blocked-credit ledger can't claim GST: the tax becomes part of the cost.
        $blocked = $kind['side'] === 'purchase'
            ? $company->accounts()->whereIn('id', array_column($data['lines'], 'account_id'))->where('itc_blocked', true)->pluck('id')->all()
            : [];

        // Outgoing batch lines print the batch's own expiry date.
        $batchExpiry = $company->stockBatches()->whereNotNull('expiry_date')->get()
            ->mapWithKeys(fn ($b) => [$b->stock_item_id.'|'.$b->batch_no => $b->expiry_date->toDateString()]);

        $billDiscount = round((float) ($data['bill_discount_percent'] ?? 0), 2);
        $lines = collect($data['lines'])->map(function (array $line) use ($interState, $blocked, $kind, $batchExpiry, $billDiscount) {
            $stockItemId = ! empty($line['stock_item_id']) ? (int) $line['stock_item_id'] : null;
            $quantity = $stockItemId ? round((float) $line['quantity'], 3) : null;
            $itemRate = $stockItemId ? round((float) $line['item_rate'], 2) : null;

            // Line value (qty × rate, or the amount entered), less the line and bill discounts, is the taxable value.
            $gross = $stockItemId ? round($quantity * $itemRate, 2) : round((float) $line['taxable'], 2);
            $discount = round((float) ($line['discount_percent'] ?? 0), 2);
            $taxable = round($gross * (1 - $discount / 100) * (1 - $billDiscount / 100), 2);
            $rate = (float) $line['rate'];
            $half = round($taxable * $rate / 200, 2);

            return [
                'account_id' => (int) $line['account_id'],
                'stock_item_id' => $stockItemId,
                'quantity' => $quantity,
                'item_rate' => $itemRate,
                'gross_amount' => $gross,
                'discount_percent' => $discount,
                'description' => $line['description'] ?? null,
                'hsn_sac' => $line['hsn_sac'] ?? null,
                'taxable' => $taxable,
                'rate' => $rate,
                'cgst' => $interState ? 0 : $half,
                'sgst' => $interState ? 0 : $half,
                'igst' => $interState ? round($taxable * $rate / 100, 2) : 0,
                'itc_eligible' => ! in_array((int) $line['account_id'], $blocked, true),
                'batch_no' => $stockItemId && filled($line['batch_no'] ?? null) ? trim($line['batch_no']) : null,
                'serial_numbers' => $stockItemId && ($list = SerialNumber::parse($line['serial_numbers'] ?? null)) ? implode(', ', $list) : null,
                'expiry_date' => ! $stockItemId || ! filled($line['batch_no'] ?? null) ? null
                    : ($kind['stockOut'] ? ($batchExpiry[$stockItemId.'|'.trim($line['batch_no'])] ?? null) : ($line['expiry_date'] ?? null)),
            ];
        });

        $voucher = DB::transaction(function () use ($company, $data, $kind, $party, $placeOfSupply, $lines, $serials, $billDiscount) {
            $partyDebit = $kind['partyDebit'];
            $total = round($lines->sum(fn ($l) => $l['taxable'] + $l['cgst'] + $l['sgst'] + $l['igst']), 2);

            // The party sits on one side; income/expense and tax ledgers on the other.
            $side = fn (float $amount, bool $debit) => $debit
                ? ['debit' => $amount, 'credit' => 0]
                : ['debit' => 0, 'credit' => $amount];

            $entries = [['account_id' => $party->id] + $side($total, $partyDebit)];

            $lineTax = fn (array $l) => $l['cgst'] + $l['sgst'] + $l['igst'];

            foreach ($lines->groupBy('account_id') as $accountId => $group) {
                // Blocked credit: the ledger carries taxable value plus the GST.
                $amount = $group->sum(fn ($l) => $l['taxable'] + ($l['itc_eligible'] ? 0 : $lineTax($l)));
                $entries[] = ['account_id' => $accountId] + $side(round($amount, 2), ! $partyDebit);
            }

            foreach (['cgst', 'sgst', 'igst'] as $head) {
                $tax = round($lines->where('itc_eligible', true)->sum($head), 2);
                if ($tax > 0) {
                    $ledger = $company->gstLedger($kind['side'] === 'sales' ? 'output' : 'input', $head);
                    $entries[] = ['account_id' => $ledger->id] + $side($tax, ! $partyDebit);
                }
            }

            $voucher = $company->vouchers()->create([
                'type' => $data['type'],
                'number' => Voucher::nextNumber($company, $data['type'], $data['date']),
                'date' => $data['date'],
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'amount' => $total,
                'party_account_id' => $party->id,
                'place_of_supply' => $placeOfSupply,
                'bill_discount_percent' => $billDiscount,
            ]);

            $voucher->entries()->createMany($entries);
            $voucher->gstLines()->createMany($lines->all());

            $this->recordSerials($company, $voucher, $serials, $data['type']);

            if (! empty($data['document_id'])) {
                $company->businessDocuments()->whereKey($data['document_id'])->update(['status' => 'converted', 'voucher_id' => $voucher->id]);
            }

            foreach ($lines->whereNotNull('stock_item_id') as $line) {
                $batch = null;
                if ($line['batch_no']) {
                    // Stock coming in creates the batch; going out uses the existing one.
                    $batch = $kind['stockOut']
                        ? $company->stockBatches()->where('stock_item_id', $line['stock_item_id'])->where('batch_no', $line['batch_no'])->first()
                        : $company->stockBatches()->firstOrCreate(
                            ['stock_item_id' => $line['stock_item_id'], 'batch_no' => $line['batch_no']],
                            ['expiry_date' => $line['expiry_date']],
                        );
                }

                $company->stockMovements()->create([
                    'voucher_id' => $voucher->id,
                    'stock_item_id' => $line['stock_item_id'],
                    'stock_batch_id' => $batch?->id,
                    'date' => $data['date'],
                    'quantity_in' => $kind['stockOut'] ? 0 : $line['quantity'],
                    'quantity_out' => $kind['stockOut'] ? $line['quantity'] : 0,
                    'rate' => $line['item_rate'],
                    'amount' => $line['taxable'],
                ]);
            }

            return $voucher;
        });

        $redirect = redirect()
            ->route('companies.gst-invoices.show', [$company, $voucher])
            ->with('success', $voucher->typeLabel().' #'.$voucher->number.' saved.');

        // Credit limit: a warning, not a block (the bill is already saved).
        if ($voucher->type === 'sales' && $party->credit_limit !== null) {
            $balance = round($party->signedOpeningBalance() + (float) $party->entries()->sum('debit') - (float) $party->entries()->sum('credit'), 2);
            if ($balance > (float) $party->credit_limit) {
                $redirect->with('warning', $party->name.' now owes ₹ '.inr($balance).', above the credit limit of ₹ '.inr($party->credit_limit).'.');
            }
        }

        return $redirect;
    }

    /**
     * Batch-tracked items need a batch on every line; stock going out must come
     * from a batch that exists and holds enough.
     */
    protected function checkBatches(Company $company, array $lines, bool $stockOut): void
    {
        $tracked = $company->stockItems()->where('track_batches', true)->pluck('name', 'id');
        $needed = [];

        foreach ($lines as $i => $line) {
            $itemId = (int) ($line['stock_item_id'] ?? 0);
            if (! $itemId || ! $tracked->has($itemId)) {
                continue;
            }

            $batchNo = trim((string) ($line['batch_no'] ?? ''));
            if ($batchNo === '') {
                throw ValidationException::withMessages(["lines.$i.batch_no" => 'Line '.($i + 1).': enter the batch for '.$tracked[$itemId].'.']);
            }

            if ($stockOut) {
                $batch = $company->stockBatches()->where('stock_item_id', $itemId)->where('batch_no', $batchNo)->first();
                if (! $batch) {
                    throw ValidationException::withMessages(["lines.$i.batch_no" => 'Line '.($i + 1).': '.$tracked[$itemId].' has no batch '.$batchNo.'.']);
                }
                $needed[$batch->id] = ['batch' => $batch, 'line' => $i, 'qty' => ($needed[$batch->id]['qty'] ?? 0) + (float) $line['quantity']];
            }
        }

        foreach ($needed as ['batch' => $batch, 'line' => $i, 'qty' => $qty]) {
            if ($qty > $batch->balance() + 0.0005) {
                throw ValidationException::withMessages([
                    "lines.$i.batch_no" => 'Batch '.$batch->batch_no.' has only '.rtrim(rtrim(number_format($batch->balance(), 3), '0'), '.').' left.',
                ]);
            }
        }
    }

    /**
     * Serial-tracked items: one serial per unit. Purchases bring in new serials,
     * sales returns bring back sold ones; sales and purchase returns take out
     * serials that are in stock.
     *
     * @return array<int, array{item: int, serials: array<int, string>}>
     */
    protected function checkSerials(Company $company, array $lines, string $type): array
    {
        $tracked = $company->stockItems()->where('track_serials', true)->pluck('name', 'id');
        $result = [];

        foreach ($lines as $i => $line) {
            $itemId = (int) ($line['stock_item_id'] ?? 0);
            if (! $itemId || ! $tracked->has($itemId)) {
                continue;
            }

            $list = SerialNumber::parse($line['serial_numbers'] ?? null);
            $qty = (float) $line['quantity'];
            $fail = fn (string $msg) => throw ValidationException::withMessages(["lines.$i.serial_numbers" => 'Line '.($i + 1).': '.$msg]);

            if ($qty != floor($qty) || count($list) !== (int) $qty) {
                $fail('enter '.rtrim(rtrim(number_format($qty, 3), '0'), '.').' serial number(s) for '.$tracked[$itemId].' (found '.count($list).').');
            }

            $existing = $company->serialNumbers()->where('stock_item_id', $itemId)->whereIn('serial', $list)->get()->keyBy('serial');

            foreach ($list as $serial) {
                $unit = $existing->get($serial);
                $problem = match ($type) {
                    'purchase' => $unit ? $serial.' is already recorded.' : null,
                    'credit_note' => ! $unit || $unit->inStock() ? $serial.' was not sold, so it can\'t come back.' : null,
                    default => ! $unit || ! $unit->inStock() ? $serial.' is not in stock.' : null, // sales, debit_note
                };
                if ($problem) {
                    $fail($problem);
                }
            }

            $result[] = ['item' => $itemId, 'serials' => $list];
        }

        return $result;
    }

    protected function recordSerials(Company $company, Voucher $voucher, array $serials, string $type): void
    {
        foreach ($serials as ['item' => $itemId, 'serials' => $list]) {
            foreach ($list as $serial) {
                match ($type) {
                    'purchase' => $company->serialNumbers()->create(['stock_item_id' => $itemId, 'serial' => $serial, 'in_voucher_id' => $voucher->id]),
                    'credit_note' => $company->serialNumbers()->where('stock_item_id', $itemId)->where('serial', $serial)->update(['out_voucher_id' => null]),
                    default => $company->serialNumbers()->where('stock_item_id', $itemId)->where('serial', $serial)->update(['out_voucher_id' => $voucher->id]),
                };
            }
        }
    }

    /**
     * Download the e-invoice JSON (NIC schema 1.1) for upload to the e-invoice portal.
     */
    public function eInvoice(Company $company, Voucher $voucher)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $voucher->company_id === (int) $company->id && $voucher->gstLines()->exists(), 404);

        $voucher->load('gstLines', 'party', 'company');
        if ($problems = EInvoice::problems($voucher)) {
            return back()->with('einvoice_problems', $problems);
        }

        $json = json_encode([EInvoice::build($voucher)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $filename = 'einvoice-'.str_replace('/', '-', $voucher->number.'-'.$voucher->financialYearLabel()).'.json';

        return response()->streamDownload(fn () => print($json), $filename, ['Content-Type' => 'application/json']);
    }

    /**
     * Download the e-way bill JSON (bulk-upload format) with the transport details entered.
     */
    public function eWayBill(Request $request, Company $company, Voucher $voucher)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $voucher->company_id === (int) $company->id && $voucher->gstLines()->exists(), 404);

        $transport = $request->validate([
            'mode' => ['required', Rule::in(array_keys(EWayBill::MODES))],
            'distance' => ['required', 'integer'],
            'vehicle_no' => ['nullable', 'string', 'max:20'],
            'transporter_id' => ['nullable', 'string', 'max:15'],
            'transporter_name' => ['nullable', 'string', 'max:100'],
            'trans_doc_no' => ['nullable', 'string', 'max:15'],
        ]);

        $voucher->load('gstLines', 'party', 'company');
        if ($problems = EWayBill::problems($voucher, $transport)) {
            return back()->withInput()->with('ewaybill_problems', $problems);
        }

        $json = json_encode(EWayBill::build($voucher, $transport), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $filename = 'ewaybill-'.str_replace('/', '-', $voucher->number.'-'.$voucher->financialYearLabel()).'.json';

        return response()->streamDownload(fn () => print($json), $filename, ['Content-Type' => 'application/json']);
    }

    /**
     * Save the e-way bill number, date and validity after generating it on the portal.
     */
    public function saveEWayBillNumber(Request $request, Company $company, Voucher $voucher)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $voucher->company_id === (int) $company->id && $voucher->gstLines()->exists(), 404);

        $request->merge(['eway_bill_no' => preg_replace('/\D/', '', (string) $request->input('eway_bill_no'))]);
        $data = $request->validate([
            'eway_bill_no' => ['nullable', 'digits:12'],
            'eway_bill_date' => ['nullable', 'required_with:eway_bill_no', 'date', 'after_or_equal:'.$voucher->date->toDateString()],
            'eway_bill_valid_until' => ['nullable', 'date', 'after_or_equal:eway_bill_date'],
        ], [
            'eway_bill_no.digits' => 'The e-way bill number has 12 digits.',
            'eway_bill_date.after_or_equal' => 'The e-way bill date can\'t be before the invoice date.',
        ]);

        // Clearing the number clears the rest.
        $voucher->update(filled($data['eway_bill_no'])
            ? $data
            : ['eway_bill_no' => null, 'eway_bill_date' => null, 'eway_bill_valid_until' => null]);

        return back()->with('success', filled($data['eway_bill_no']) ? 'E-way bill '.$data['eway_bill_no'].' saved.' : 'E-way bill details removed.');
    }

    /**
     * Narrow receipt for thermal printers (80 mm or 58 mm).
     */
    public function receipt(Request $request, Company $company, Voucher $voucher)
    {
        $this->authorizeCompany($company);
        abort_unless((int) $voucher->company_id === (int) $company->id && $voucher->gstLines()->exists(), 404);

        $width = (int) $request->input('width', 80) === 58 ? 58 : 80;
        $voucher->load('gstLines.account', 'gstLines.stockItem.unit', 'party');

        return view('gst-invoices.receipt', compact('company', 'voucher', 'width'));
    }

    /**
     * Read-only invoice for the customer, opened from a signed link (no login).
     */
    public function publicShow(Voucher $voucher)
    {
        abort_unless($voucher->gstLines()->exists(), 404);

        $voucher->load('gstLines.account', 'gstLines.stockItem.unit', 'party', 'company');

        return view('gst-invoices.public', ['company' => $voucher->company, 'voucher' => $voucher]);
    }

    public function show(Company $company, Voucher $voucher)
    {
        $this->authorizeCompany($company);
        abort_unless($voucher->gstLines()->exists(), 404);

        $voucher->load('gstLines.account', 'gstLines.stockItem.unit', 'party');

        return view('gst-invoices.show', compact('company', 'voucher'));
    }
}
