<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Company;
use App\Models\Godown;
use App\Models\Invoice;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Services\Inventory\BatchTracker;
use App\Services\Inventory\DeliveryNoteService;
use App\Support\CurrentCompany;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves, posts and cancels invoices (sales, purchases, credit and debit notes).
 *
 * The invoice keeps the commercial detail (items, quantities, tax); the
 * accounting effect is a voucher built here and written by VoucherService,
 * so the double-entry rules, numbering, period locks and bill-wise checks are
 * the same as for any other voucher.
 *
 * Input: voucher_type_id, voucher_number (manual numbering), date, due_date,
 * party_account_id, reference, reference_date, original_invoice_id,
 * place_of_supply, billing_address, shipping_address, narration, terms,
 * items: [account_id, stock_item_id, godown_id, description, hsn_sac, quantity, unit, rate, discount_percent, gst_rate,
 *   batch_no, mfg_date, expiry_date, serials (for items tracked by batch or serial)]
 */
class InvoiceService
{
    public function __construct(
        protected CurrentCompany $current,
        protected VoucherService $vouchers,
        protected InvoiceCalculator $calculator,
        protected BatchTracker $batches,
    ) {}

    /** @param string $intent "post" or "draft" */
    public function save(?Invoice $invoice, string $kind, array $data, User $user, string $intent = 'post', ?string $reason = null): Invoice
    {
        $config = Invoice::kindConfig($kind);

        if ($invoice && $invoice->status() === Voucher::CANCELLED) {
            throw ValidationException::withMessages(['invoice' => 'A cancelled '.mb_strtolower($config['label']).' cannot be altered.']);
        }

        return DB::transaction(function () use ($invoice, $kind, $config, $data, $user, $intent, $reason) {
            [$party, $type, $items, $taxMode, $result] = $this->prepare($invoice, $kind, $config, $data);
            $details = $party->partyDetails;

            $invoice ??= new Invoice(['kind' => $kind, 'created_by' => $user->id]);
            $date = Carbon::parse($data['date'])->toDateString();
            $dueDate = $data['due_date'] ?? null;
            if (! $dueDate && $party->credit_period_days && in_array($kind, ['sales', 'purchase'], true)) {
                $dueDate = Carbon::parse($date)->addDays($party->credit_period_days)->toDateString();
            }

            $totals = $result['totals'];
            $invoice->fill([
                'voucher_type_id' => $type->id,
                'party_account_id' => $party->id,
                'original_invoice_id' => $data['original_invoice_id'] ?? null,
                'date' => $date,
                'due_date' => $dueDate,
                'reference' => $data['reference'] ?? null,
                'reference_date' => $data['reference_date'] ?? null,
                'party_name' => $party->name,
                'party_gstin' => $details?->gstin,
                'party_registration_type' => $details?->gst_registration_type,
                'billing_address' => $data['billing_address'] ?? $details?->fullAddress(),
                'shipping_address' => $data['shipping_address'] ?? $details?->shipping_address,
                'place_of_supply' => $data['place_of_supply'] ?? $details?->state,
                'tax_mode' => $taxMode,
                'gross_total' => Money::fromPaise($totals['gross']),
                'discount_total' => Money::fromPaise($totals['discount']),
                'taxable_total' => Money::fromPaise($totals['taxable']),
                'cgst_total' => Money::fromPaise($totals['cgst']),
                'sgst_total' => Money::fromPaise($totals['sgst']),
                'igst_total' => Money::fromPaise($totals['igst']),
                'round_off' => Money::fromPaise($totals['round_off']),
                'grand_total' => Money::fromPaise($totals['grand']),
                'narration' => $data['narration'] ?? null,
                'terms' => $data['terms'] ?? null,
                'updated_by' => $user->id,
            ]);
            $invoice->save();

            $invoice->items()->delete();
            foreach ($items as $n => $item) {
                $line = $result['lines'][$n];
                $invoice->items()->create([
                    'company_id' => $invoice->company_id,
                    'line_no' => $n + 1,
                    'account_id' => $item['account']->id,
                    'stock_item_id' => $item['stock_item_id'],
                    'godown_id' => $item['godown_id'],
                    'order_item_id' => $item['order_item_id'],
                    'note_item_id' => $item['note_item_id'],
                    'description' => $item['description'],
                    'hsn_sac' => $item['hsn_sac'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'rate' => Money::normalize($item['rate']),
                    'discount_percent' => $item['discount_percent'],
                    'gross_amount' => Money::fromPaise($line['gross']),
                    'discount_amount' => Money::fromPaise($line['discount']),
                    'taxable_amount' => Money::fromPaise($line['taxable']),
                    'gst_rate' => $taxMode === 'none' ? '0' : $item['gst_rate'],
                    'cgst_amount' => Money::fromPaise($line['cgst']),
                    'sgst_amount' => Money::fromPaise($line['sgst']),
                    'igst_amount' => Money::fromPaise($line['igst']),
                    'total_amount' => Money::fromPaise($line['total']),
                    'batch_no' => $item['batch_no'],
                    'mfg_date' => $item['mfg_date'],
                    'expiry_date' => $item['expiry_date'],
                    'serials' => $item['serials'],
                ]);
            }

            $voucher = $invoice->voucher;
            $status = $voucher?->isPosted() ? Voucher::POSTED : $this->vouchers->resolveStatus($user, $intent);

            if ($status !== Voucher::DRAFT && $totals['grand'] <= 0) {
                throw ValidationException::withMessages(['items' => 'The invoice total must be greater than zero.']);
            }

            $input = $this->voucherInput($invoice, $party, $type, $data, $result, $items);

            try {
                if ($voucher) {
                    $this->vouchers->update($voucher, $input, $user, $status, $reason);
                } elseif ($status !== Voucher::DRAFT) {
                    $voucher = $this->vouchers->create($input, $user, $status, $invoice);
                    $invoice->update(['voucher_id' => $voucher->id]);
                }
            } catch (ValidationException $e) {
                throw $this->voucherErrors($e);
            }

            return $invoice->fresh(['items.account', 'voucher', 'party.partyDetails']);
        });
    }

    /** Post a draft or pending invoice using its saved data. */
    public function post(Invoice $invoice, User $user): Invoice
    {
        return $this->save($invoice, $invoice->kind, $this->toInput($invoice), $user, 'post');
    }

    public function cancel(Invoice $invoice, User $user, string $reason): Invoice
    {
        if ($invoice->status() !== Voucher::POSTED) {
            throw ValidationException::withMessages(['invoice' => 'Only posted documents can be cancelled.']);
        }
        if ($invoice->isReturn() === false && Invoice::query()->where('original_invoice_id', $invoice->id)
            ->whereHas('voucher', fn ($q) => $q->where('status', Voucher::POSTED))->exists()) {
            throw ValidationException::withMessages(['invoice' => 'A posted credit / debit note refers to this invoice. Cancel the note first.']);
        }

        $this->vouchers->cancel($invoice->voucher, $user, $reason);

        return $invoice->fresh('voucher');
    }

    /** Delete a draft (or pending) invoice; posted ones are cancelled instead. */
    public function delete(Invoice $invoice): void
    {
        $voucher = $invoice->voucher;
        if ($voucher && ($voucher->isPosted() || $voucher->isCancelled())) {
            throw ValidationException::withMessages(['invoice' => 'Posted documents cannot be deleted. Cancel them instead.']);
        }

        DB::transaction(function () use ($invoice, $voucher) {
            $invoice->items()->delete();
            $invoice->delete();
            if ($voucher) {
                $this->vouchers->delete($voucher);
            }
        });
    }

    /** Saved invoice as save() input. */
    public function toInput(Invoice $invoice): array
    {
        $invoice->loadMissing('items', 'voucher');

        return [
            'voucher_type_id' => $invoice->voucher_type_id,
            'voucher_number' => $invoice->voucher?->voucher_number,
            'date' => $invoice->date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'party_account_id' => $invoice->party_account_id,
            'reference' => $invoice->reference,
            'reference_date' => $invoice->reference_date?->toDateString(),
            'original_invoice_id' => $invoice->original_invoice_id,
            'place_of_supply' => $invoice->place_of_supply,
            'billing_address' => $invoice->billing_address,
            'shipping_address' => $invoice->shipping_address,
            'narration' => $invoice->narration,
            'terms' => $invoice->terms,
            'items' => $invoice->items->map(fn ($i) => [
                'account_id' => $i->account_id,
                'stock_item_id' => $i->stock_item_id,
                'godown_id' => $i->godown_id,
                'order_item_id' => $i->order_item_id,
                'note_item_id' => $i->note_item_id,
                'description' => $i->description,
                'hsn_sac' => $i->hsn_sac,
                'quantity' => $i->quantity,
                'unit' => $i->unit,
                'rate' => $i->rate,
                'discount_percent' => $i->discount_percent,
                'gst_rate' => $i->gst_rate,
                'batch_no' => $i->batch_no,
                'mfg_date' => $i->mfg_date?->toDateString(),
                'expiry_date' => $i->expiry_date?->toDateString(),
                'serials' => $i->serials,
            ])->all(),
        ];
    }

    /**
     * intra (CGST + SGST), inter (IGST) or none.
     *
     * Sales: the place of supply against the company's state. Purchases: the
     * supplier's state against the company's state; unregistered and
     * composition suppliers do not charge GST.
     */
    public function taxMode(string $kind, ?string $state, ?string $registrationType): string
    {
        $company = $this->company();

        if (! $company->setting('enable_gst')) {
            return 'none';
        }
        $purchase = Invoice::kindConfig($kind)['tax'] === 'input';
        if ($purchase && in_array($registrationType, ['composition', 'unregistered', 'consumer'], true)) {
            return 'none';
        }
        if (in_array($registrationType, ['overseas', 'sez'], true)) {
            return 'inter';
        }
        if (! $company->state || ! $state) {
            return 'intra';
        }

        return strcasecmp(trim($company->state), trim($state)) === 0 ? 'intra' : 'inter';
    }

    /** Ledgers a party of this kind may be: the kind's party group, or cash/bank for cash sales and purchases. */
    public function partyQuery(string $kind)
    {
        $config = Invoice::kindConfig($kind);
        $groupIds = AccountGroup::descendantIdsOf([AccountGroup::idFor($config['party_group'])]);

        return Account::query()->where('is_active', true)->where(function ($q) use ($groupIds, $kind) {
            $q->where(fn ($w) => $w->where('account_type', 'party')->whereIn('account_group_id', $groupIds));
            if (in_array($kind, ['sales', 'purchase'], true)) {
                $q->orWhereIn('account_type', ['cash', 'bank']);
            }
        });
    }

    // ------------------------------------------------------------------

    /** Validate the input and compute the amounts. */
    protected function prepare(?Invoice $invoice, string $kind, array $config, array $data): array
    {
        $errors = [];

        $party = $this->partyQuery($kind)->with('partyDetails')->find($data['party_account_id'] ?? 0)
            ?? ($invoice && (int) ($data['party_account_id'] ?? 0) === $invoice->party_account_id ? $invoice->party : null);
        if (! $party) {
            throw ValidationException::withMessages(['party_account_id' => 'Select a '.($config['party_group'] === 'sundry_debtors' ? 'customer' : 'supplier').' (or a cash / bank ledger).']);
        }

        $type = VoucherType::query()->where('base_type', $config['base'])->find($data['voucher_type_id'] ?? 0)
            ?? VoucherType::forBase($config['base']);
        if (! $type) {
            throw ValidationException::withMessages(['voucher_type_id' => 'No voucher type exists for this document. Create one under Voucher Types.']);
        }

        if (! empty($data['original_invoice_id'])) {
            $originalKind = $kind === 'credit_note' ? 'sales' : ($kind === 'debit_note' ? 'purchase' : null);
            $original = $originalKind ? Invoice::query()->ofKind($originalKind)->with('voucher')->find($data['original_invoice_id']) : null;
            if (! $original || $original->status() !== Voucher::POSTED || $original->party_account_id !== $party->id) {
                $errors['original_invoice_id'] = 'Select a posted invoice of the same party.';
            }
        }

        $allowedRates = config('erp.gst_rates');
        $rows = array_values($data['items'] ?? []);
        $stockItems = StockItem::query()->with('unit')->whereIn('id', array_filter(array_map(fn ($r) => (int) ($r['stock_item_id'] ?? 0), $rows)))->get()->keyBy('id');
        $ledgerColumn = $config['item_type'] === 'sales' ? 'sales_account_id' : 'purchase_account_id';
        // A stock item supplies its ledger when the row has none.
        foreach ($rows as $i => $row) {
            $stock = $stockItems->get((int) ($row['stock_item_id'] ?? 0));
            if ($stock && empty($row['account_id']) && $stock->{$ledgerColumn}) {
                $rows[$i]['account_id'] = $stock->{$ledgerColumn};
            }
        }
        $godownIds = Godown::query()->when(! $invoice, fn ($q) => $q->where('is_active', true))->pluck('id')->all();
        $defaultGodown = Godown::defaultId();
        $previousItems = $invoice ? $invoice->items()->pluck('stock_item_id')->filter()->all() : [];

        $accounts = Account::query()->whereIn('id', array_column($rows, 'account_id'))->get()->keyBy('id');
        $items = [];
        $tracked = [];
        $ordered = [];
        $noted = [];
        foreach ($rows as $i => $row) {
            if (empty($row['account_id']) && empty($row['stock_item_id']) && trim((string) ($row['description'] ?? '')) === '' && ($row['rate'] ?? '') === '') {
                continue; // blank row
            }
            $line = 'Item '.(count($items) + 1);

            $stock = null;
            $godownId = null;
            if (! empty($row['stock_item_id'])) {
                $stock = $stockItems->get((int) $row['stock_item_id']);
                if (! $stock || (! $stock->is_active && ! in_array($stock->id, $previousItems, true))) {
                    $errors["items.$i.stock_item_id"] = "{$line}: choose an active stock item.";

                    continue;
                }
                $godownId = (int) ($row['godown_id'] ?? 0) ?: $defaultGodown;
                if (! in_array($godownId, $godownIds, true)) {
                    $errors["items.$i.godown_id"] = "{$line}: choose a godown.";
                }
            }

            $account = $accounts->get((int) ($row['account_id'] ?? 0));
            if (! $account || $account->account_type !== $config['item_type']) {
                $errors["items.$i.account_id"] = "{$line}: select a ".$config['item_type'].' ledger.';
                continue;
            }
            if (! $account->is_active && ! $invoice) {
                $errors["items.$i.account_id"] = "{$line}: ledger {$account->name} is inactive.";
            }

            $quantity = trim((string) ($row['quantity'] ?? ''));
            $rate = trim((string) ($row['rate'] ?? ''));
            $discount = trim((string) ($row['discount_percent'] ?? '')) ?: '0';
            $gstInput = trim((string) ($row['gst_rate'] ?? ''));
            $gstRate = Decimal::trim($gstInput !== '' ? $gstInput : ($stock?->gst_rate ?? '0'));

            if (! preg_match('/^\d{1,7}(\.\d{1,3})?$/', $quantity) || (float) $quantity <= 0) {
                $errors["items.$i.quantity"] = "{$line}: enter a quantity greater than zero (up to 3 decimals).";
            } elseif ($stock && str_contains($quantity, '.') && strlen(rtrim(explode('.', $quantity)[1], '0')) > $stock->unit->decimal_places) {
                $errors["items.$i.quantity"] = "{$line}: unit {$stock->unit->symbol} allows {$stock->unit->decimal_places} decimal places.";
            }
            if (! preg_match('/^\d{1,11}(\.\d{1,2})?$/', $rate)) {
                $errors["items.$i.rate"] = "{$line}: enter a valid rate (up to 2 decimals).";
            }
            if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $discount) || (float) $discount > 100) {
                $errors["items.$i.discount_percent"] = "{$line}: the discount must be between 0 and 100%.";
            }
            if (! in_array($gstRate, $allowedRates, true)) {
                $errors["items.$i.gst_rate"] = "{$line}: choose a valid GST rate.";
            }
            $hsn = trim((string) ($row['hsn_sac'] ?? '')) ?: ($stock?->hsn_sac ?: $account->hsn_sac);
            if ($hsn && ! preg_match('/^\d{4,8}$/', $hsn)) {
                $errors["items.$i.hsn_sac"] = "{$line}: HSN / SAC must be 4 to 8 digits.";
            }

            $items[] = [
                'account' => $account,
                'stock_item_id' => $stock?->id,
                'godown_id' => $godownId,
                'description' => mb_substr(trim((string) ($row['description'] ?? '')) ?: ($stock?->name ?? $account->name), 0, 500),
                'hsn_sac' => $hsn ?: null,
                'quantity' => $quantity,
                'unit' => mb_substr(trim((string) ($row['unit'] ?? '')), 0, 20) ?: $stock?->unit->symbol,
                'rate' => $rate,
                'discount_percent' => $discount,
                'gst_rate' => $gstRate,
                'batch_no' => null, 'mfg_date' => null, 'expiry_date' => null, 'serials' => null,
                'order_item_id' => ! empty($row['order_item_id']) ? (int) $row['order_item_id'] : null,
                'note_item_id' => ! empty($row['note_item_id']) ? (int) $row['note_item_id'] : null,
            ];
            if (! empty($row['note_item_id'])) {
                $noted[count($items) - 1] = ['key' => "items.$i", 'label' => $line, 'note_item_id' => (int) $row['note_item_id'],
                    'stock_item_id' => $stock?->id, 'quantity' => $quantity];
            }
            if (! empty($row['order_item_id'])) {
                $ordered[] = ['key' => "items.$i", 'label' => $line, 'order_item_id' => (int) $row['order_item_id'],
                    'stock_item_id' => $stock?->id, 'quantity' => $quantity];
            }
            if ($stock && $stock->tracking !== 'none' && empty($row['note_item_id'])) {
                $tracked[count($items) - 1] = [
                    'key' => "items.$i", 'label' => $line, 'item' => $stock, 'godown_id' => $godownId,
                    'direction' => in_array($kind, ['sales', 'debit_note'], true) ? 'out' : 'in', 'quantity' => $quantity,
                    'batch_no' => $row['batch_no'] ?? null, 'mfg_date' => $row['mfg_date'] ?? null,
                    'expiry_date' => $row['expiry_date'] ?? null, 'serials' => $row['serials'] ?? null,
                ];
            }
        }

        // Lines against a delivery / receipt note: the note moved the stock, so its godown and batches apply.
        if ($noted && ! $errors) {
            [$noteErrors, $noteLines] = app(DeliveryNoteService::class)->checkInvoiceLines($kind, $party->id, $noted, $invoice?->exists ? $invoice : null);
            $errors += $noteErrors;
            foreach ($noteLines as $n => $ni) {
                $items[$n]['godown_id'] = $ni->godown_id;
                $items[$n]['order_item_id'] = null; // the note already counts against the order
                $items[$n]['batch_no'] = $ni->batch_no;
                $items[$n]['mfg_date'] = $ni->mfg_date?->toDateString();
                $items[$n]['expiry_date'] = $ni->expiry_date?->toDateString();
                $items[$n]['serials'] = $ni->serials;
            }
        }
        if ($ordered && ! $errors) {
            $errors += app(OrderService::class)->checkInvoiceLines($kind, $party->id, $ordered, $invoice?->exists ? $invoice : null);
        }
        if ($tracked && ! $errors) {
            [$fields, $batchErrors] = $this->batches->check($tracked, $this->documentDate($data), $invoice?->exists ? ['invoice', $invoice->id] : null, $kind === 'sales');
            $errors += $batchErrors;
            foreach ($fields as $n => $f) {
                $items[$n] = array_merge($items[$n], $f);
            }
        }

        if ($items === [] && ! $errors) {
            $errors['items'] = 'Add at least one item.';
        }
        if (count($items) > 200) {
            $errors['items'] = 'An invoice can have at most 200 items.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $details = $party->partyDetails;
        $state = $config['tax'] === 'input' ? ($details?->state ?? $data['place_of_supply'] ?? null) : ($data['place_of_supply'] ?? $details?->state);
        $taxMode = $this->taxMode($kind, $state, $details?->gst_registration_type);

        try {
            $result = $this->calculator->calculate($items, $taxMode, (bool) $this->company()->setting('invoice_round_off'));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return [$party, $type, $items, $taxMode, $result];
    }

    /** The accounting voucher for the invoice. */
    protected function voucherInput(Invoice $invoice, Account $party, VoucherType $type, array $data, array $result, array $items): array
    {
        $config = $invoice->config();
        $totals = $result['totals'];
        $itemSide = in_array($invoice->kind, ['sales', 'debit_note'], true) ? 'Cr' : 'Dr';
        $partySide = $itemSide === 'Cr' ? 'Dr' : 'Cr';
        $own = $invoice->voucher?->voucher_number ?: Voucher::OWN_NUMBER;

        $bills = [];
        if ($party->maintain_bill_wise && $totals['grand'] > 0) {
            $original = $invoice->original_invoice_id ? Invoice::with('voucher')->find($invoice->original_invoice_id) : null;
            if ($original) {
                $bills[] = ['bill_type' => 'agst_ref', 'bill_ref' => $this->billRef($original), 'amount' => Money::fromPaise($totals['grand'])];
            } else {
                $ref = $invoice->kind === 'purchase' && $invoice->reference ? $invoice->reference : $own;
                $bills[] = ['bill_type' => 'new_ref', 'bill_ref' => $ref, 'amount' => Money::fromPaise($totals['grand']), 'due_date' => $invoice->due_date?->toDateString()];
            }
        }

        $entries = [[
            'account_id' => $party->id,
            'side' => $partySide,
            'amount' => Money::fromPaise($totals['grand']),
            'bills' => $bills,
        ]];

        $byLedger = [];
        foreach ($items as $n => $item) {
            $byLedger[$item['account']->id] = ($byLedger[$item['account']->id] ?? 0) + $result['lines'][$n]['taxable'];
        }
        foreach ($byLedger as $accountId => $paise) {
            if ($paise > 0) {
                $entries[] = ['account_id' => $accountId, 'side' => $itemSide, 'amount' => Money::fromPaise($paise)];
            }
        }

        foreach (['cgst', 'sgst', 'igst'] as $tax) {
            if ($totals[$tax] > 0) {
                $key = $config['tax'].'_'.$tax;
                $ledgerId = Account::idFor($key) ?? throw ValidationException::withMessages(['items' => "The {$key} ledger is missing."]);
                $entries[] = ['account_id' => $ledgerId, 'side' => $itemSide, 'amount' => Money::fromPaise($totals[$tax])];
            }
        }

        if ($totals['round_off'] !== 0) {
            $entries[] = [
                'account_id' => Account::idFor('round_off') ?? throw ValidationException::withMessages(['items' => 'The Round Off ledger is missing.']),
                'side' => $totals['round_off'] > 0 ? $itemSide : $partySide,
                'amount' => Money::fromPaise(abs($totals['round_off'])),
            ];
        }

        return [
            'voucher_type_id' => $type->id,
            'voucher_number' => $data['voucher_number'] ?? null,
            'date' => $invoice->date->toDateString(),
            'reference' => $invoice->reference,
            'reference_date' => $invoice->reference_date?->toDateString(),
            'narration' => $invoice->narration ?: $config['label'].' — '.$party->name,
            'entries' => $entries,
        ];
    }

    /** Voucher-level errors (line and bill keys) re-keyed for the invoice form. */
    protected function voucherErrors(ValidationException $e): ValidationException
    {
        $messages = [];
        foreach ($e->errors() as $key => $list) {
            $target = in_array($key, ['date', 'voucher_number', 'voucher_type_id'], true) ? $key : 'invoice';
            $messages[$target] = array_merge($messages[$target] ?? [], array_map(
                fn ($m) => preg_replace('/^Line 1(, bill 1)?: /', 'Party: ', $m), $list));
        }

        return ValidationException::withMessages($messages);
    }

    /** The bill reference an invoice opened in the party's account. */
    public function billRef(Invoice $invoice): string
    {
        return $invoice->kind === 'purchase' && $invoice->reference ? $invoice->reference : (string) $invoice->number();
    }

    /** The document date as Y-m-d (today when missing or invalid; the voucher rejects bad dates). */
    protected function documentDate(array $data): string
    {
        try {
            return Carbon::parse((string) ($data['date'] ?? 'today'))->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }

    protected function company(): Company
    {
        return $this->current->get() ?? throw new \LogicException('No active company.');
    }
}
