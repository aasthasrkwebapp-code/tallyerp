<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Godown;
use App\Models\Invoice;
use App\Models\StockItem;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Billing\InvoiceService;
use App\Services\Inventory\StockJournalService;
use App\Services\Reports\Outstanding;
use App\Support\CurrentCompany;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * Sales invoices, purchase bills, credit notes and debit notes.
 */
class InvoiceController extends Controller
{
    public function __construct(protected InvoiceService $invoices) {}

    public function index(Request $request)
    {
        $kind = $this->kind($request);
        $this->authorizeKind($request, $kind, 'view');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:'.implode(',', array_keys(Voucher::STATUSES))],
            'party' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = Invoice::query()->ofKind($kind)
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('party_name', 'like', "%{$v}%")
                ->orWhere('reference', 'like', "%{$v}%")
                ->orWhereHas('voucher', fn ($vq) => $vq->where('voucher_number', 'like', "%{$v}%"))))
            ->when($filters['party'] ?? null, fn ($q, $v) => $q->where('party_account_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $v === Voucher::DRAFT
                ? $q->where(fn ($w) => $w->whereNull('voucher_id')->orWhereHas('voucher', fn ($vq) => $vq->where('status', Voucher::DRAFT)))
                : $q->whereHas('voucher', fn ($vq) => $vq->where('status', $v)));

        $postedTotal = (clone $query)->whereHas('voucher', fn ($q) => $q->where('status', Voucher::POSTED))
            ->get(['grand_total'])->sum(fn ($i) => Money::toPaise($i->grand_total));

        return view('billing.invoices.index', [
            'kind' => $kind,
            'config' => Invoice::kindConfig($kind),
            'invoices' => $query->with('voucher')->orderByDesc('date')->orderByDesc('id')->paginate(50)->withQueryString(),
            'filters' => $filters,
            'postedTotal' => $postedTotal,
        ]);
    }

    public function create(Request $request)
    {
        $kind = $this->kind($request);
        $this->authorizeKind($request, $kind, 'create');
        $config = Invoice::kindConfig($kind);

        $invoice = new Invoice([
            'kind' => $kind,
            'date' => now()->toDateString(),
            'voucher_type_id' => VoucherType::forBase($config['base'])?->id,
            'terms' => $config['tax'] === 'output' ? app(CurrentCompany::class)->get()->setting('invoice_terms') : null,
        ]);
        $input = $this->invoices->toInput($invoice->setRelation('items', collect()));

        // "Credit note for invoice X" / "Debit note for bill X".
        if ($request->filled('from') && $invoice->isReturn()) {
            $original = Invoice::query()->ofKind($kind === 'credit_note' ? 'sales' : 'purchase')->findOrFail($request->integer('from'));
            $input = array_merge($this->invoices->toInput($original), [
                'voucher_type_id' => $invoice->voucher_type_id,
                'voucher_number' => null,
                'date' => now()->toDateString(),
                'due_date' => null,
                'reference' => null,
                'reference_date' => null,
                'original_invoice_id' => $original->id,
                'narration' => null,
                'terms' => null,
            ]);
        } elseif ($request->filled('party')) {
            $input['party_account_id'] = $request->integer('party');
        }

        return $this->form($invoice, $input);
    }

    public function store(Request $request)
    {
        $kind = $this->kind($request);
        $this->authorizeKind($request, $kind, 'create');

        $invoice = $this->invoices->save(null, $kind, $this->input($request), $request->user(), $request->input('action', 'post'));

        return redirect()->route('invoices.show', $invoice)
            ->with('success', $this->savedMessage($invoice))
            ->with('warning', $this->warnings($invoice));
    }

    public function show(Request $request, Invoice $invoice)
    {
        $this->authorizeKind($request, $invoice->kind, 'view');
        $invoice->load(['items.account', 'voucher.type', 'party.partyDetails', 'originalInvoice.voucher', 'creator']);

        return view('billing.invoices.show', [
            'invoice' => $invoice,
            'config' => $invoice->config(),
            'taxSummary' => $this->taxSummary($invoice),
            'returns' => Invoice::query()->where('original_invoice_id', $invoice->id)->with('voucher')->get(),
        ]);
    }

    public function edit(Request $request, Invoice $invoice)
    {
        $this->authorizeKind($request, $invoice->kind, 'edit');
        abort_unless($invoice->isEditable(), 403, 'Cancelled documents cannot be altered.');

        return $this->form($invoice, $this->invoices->toInput($invoice));
    }

    public function update(Request $request, Invoice $invoice)
    {
        $this->authorizeKind($request, $invoice->kind, 'edit');
        abort_unless($invoice->isEditable(), 403, 'Cancelled documents cannot be altered.');

        if ($invoice->status() === Voucher::POSTED) {
            $request->validate(['alteration_reason' => ['required', 'string', 'max:255']], ['alteration_reason.required' => 'Enter the reason for altering a posted document.']);
        }

        $invoice = $this->invoices->save($invoice, $invoice->kind, $this->input($request), $request->user(), $request->input('action', 'post'), $request->input('alteration_reason'));

        return redirect()->route('invoices.show', $invoice)
            ->with('success', $this->savedMessage($invoice))
            ->with('warning', $this->warnings($invoice));
    }

    public function post(Request $request, Invoice $invoice)
    {
        $this->authorizeKind($request, $invoice->kind, 'edit');
        abort_unless(in_array($invoice->status(), [Voucher::DRAFT, Voucher::PENDING], true), 403);
        abort_if($invoice->status() === Voucher::PENDING && $request->user()->cannot('vouchers.approve'), 403, 'Only approvers can post pending documents.');

        $invoice = $this->invoices->post($invoice, $request->user());

        return redirect()->route('invoices.show', $invoice)->with('success', $this->savedMessage($invoice))->with('warning', $this->warnings($invoice));
    }

    public function cancel(Request $request, Invoice $invoice)
    {
        $this->authorizeKind($request, $invoice->kind, 'delete');
        abort_unless($request->user()->can('vouchers.cancel'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->invoices->cancel($invoice, $request->user(), $data['reason']);

        return redirect()->route('invoices.show', $invoice)->with('success', $invoice->config()['label'].' '.$invoice->displayNumber().' cancelled.');
    }

    public function destroy(Request $request, Invoice $invoice)
    {
        $this->authorizeKind($request, $invoice->kind, 'delete');
        $kind = $invoice->kind;

        $this->invoices->delete($invoice);

        return redirect()->route('invoices.index', ['kind' => $kind])->with('success', 'Draft deleted.');
    }

    public function print(Request $request, Invoice $invoice)
    {
        return view('billing.invoices.print', $this->printData($request, $invoice));
    }

    public function pdf(Request $request, Invoice $invoice)
    {
        $data = $this->printData($request, $invoice);
        $name = \Illuminate\Support\Str::slug($invoice->config()['label'].' '.$invoice->displayNumber()).'.pdf';
        \App\Models\AuditLog::record('invoice_pdf', $invoice, null, null, "PDF downloaded: {$invoice->displayNumber()}");

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.invoice', $data)->setPaper('a4')->download($name);
    }

    protected function printData(Request $request, Invoice $invoice): array
    {
        $this->authorizeKind($request, $invoice->kind, 'view');
        $invoice->load(['items', 'voucher', 'originalInvoice.voucher']);
        $company = app(CurrentCompany::class)->get();

        $bank = $company->setting('invoice_show_bank_details') && $invoice->kind === 'sales'
            ? Account::query()->where('account_type', 'bank')->where('is_active', true)->whereNotNull('bank_account_number')->orderBy('id')->first()
            : null;

        return [
            'invoice' => $invoice,
            'config' => $invoice->config(),
            'company' => $company,
            'taxSummary' => $this->taxSummary($invoice),
            'bank' => $bank,
        ];
    }

    // ------------------------------------------------------------------

    protected function form(Invoice $invoice, array $input)
    {
        $kind = $invoice->kind;
        $config = $invoice->config();
        $company = app(CurrentCompany::class)->get();

        $old = $this->oldInput();
        if ($old) {
            $input = array_merge($input, $old);
        }

        $parties = $this->invoices->partyQuery($kind)->with('partyDetails')->orderBy('name')->get();
        if ($invoice->party_account_id && ! $parties->contains('id', $invoice->party_account_id)) {
            $parties->push($invoice->party()->with('partyDetails')->first());
        }

        $originals = $invoice->isReturn()
            ? Invoice::query()->ofKind($kind === 'credit_note' ? 'sales' : 'purchase')
                ->whereHas('voucher', fn ($q) => $q->where('status', Voucher::POSTED))
                ->with('voucher')->latest('date')->limit(500)->get()
            : collect();

        return view('billing.invoices.form', [
            'invoice' => $invoice,
            'kind' => $kind,
            'config' => $config,
            'input' => $input,
            'types' => VoucherType::query()->where('base_type', $config['base'])->where('is_active', true)->orderBy('name')->get(),
            'parties' => $parties->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->account_type,
                'state' => $a->partyDetails?->state,
                'reg' => $a->partyDetails?->gst_registration_type,
                'gstin' => $a->partyDetails?->gstin,
                'billing' => $a->partyDetails?->fullAddress(),
                'shipping' => $a->partyDetails?->shipping_address,
                'credit_days' => $a->credit_period_days,
            ])->values(),
            'ledgers' => Account::query()->where('account_type', $config['item_type'])->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'hsn_sac', 'gst_rate'])
                ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'hsn' => $a->hsn_sac, 'gst' => $a->gst_rate !== null ? Decimal::trim($a->gst_rate) : null])
                ->values(),
            'stockItems' => StockItem::query()->with('unit')
                ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', array_filter(array_column($input['items'] ?? [], 'stock_item_id'))))
                ->orderBy('name')->get()
                ->map(fn (StockItem $s) => [
                    'id' => $s->id, 'name' => $s->name, 'unit' => $s->unit->symbol, 'hsn' => $s->hsn_sac,
                    'gst' => $s->gst_rate !== null ? Decimal::trim($s->gst_rate) : null,
                    'rate' => ($r = $config['item_type'] === 'sales' ? $s->sales_rate : $s->purchase_rate) !== null ? Decimal::trim($r) : null,
                    'ledger' => $config['item_type'] === 'sales' ? $s->sales_account_id : $s->purchase_account_id,
                ])->values(),
            'godowns' => Godown::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'originals' => $originals->map(fn (Invoice $i) => [
                'id' => $i->id, 'party' => $i->party_account_id,
                'label' => $i->displayNumber().' · '.$i->date->format('d-m-Y').' · '.Money::format($i->grand_total),
            ])->values(),
            'company' => $company,
            'states' => array_values(config('india.states')),
            'gstRates' => config('erp.gst_rates'),
            'gstEnabled' => (bool) $company->setting('enable_gst'),
        ]);
    }

    protected function oldInput(): array
    {
        $old = session()->getOldInput();
        if (! $old) {
            return [];
        }
        $old['items'] = array_values($old['items'] ?? []);

        return $old;
    }

    protected function input(Request $request): array
    {
        $request->validate([
            'voucher_type_id' => ['nullable', 'integer'],
            'voucher_number' => ['nullable', 'string', 'max:50'],
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:date'],
            'party_account_id' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:50'],
            'reference_date' => ['nullable', 'date'],
            'original_invoice_id' => ['nullable', 'integer'],
            'place_of_supply' => ['nullable', 'string', 'in:'.implode(',', array_values(config('india.states')))],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'max:200'],
            'items.*.account_id' => ['nullable', 'integer'],
            'items.*.stock_item_id' => ['nullable', 'integer'],
            'items.*.godown_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.hsn_sac' => ['nullable', 'string', 'max:10'],
            'items.*.quantity' => ['nullable', 'string', 'max:20'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.rate' => ['nullable', 'string', 'max:20'],
            'items.*.discount_percent' => ['nullable', 'string', 'max:10'],
            'items.*.gst_rate' => ['nullable', 'string', 'max:10'],
        ], [
            'party_account_id.required' => 'Select the party.',
            'items.required' => 'Add at least one item.',
        ]);

        $data = $request->only([
            'voucher_type_id', 'voucher_number', 'date', 'due_date', 'party_account_id', 'reference', 'reference_date',
            'original_invoice_id', 'place_of_supply', 'billing_address', 'shipping_address', 'narration', 'terms',
        ]);
        $data['items'] = array_values($request->input('items', []));

        return $data;
    }

    /** Taxable value and tax per GST rate (and HSN), as printed under the items. */
    protected function taxSummary(Invoice $invoice): array
    {
        $rows = [];
        foreach ($invoice->items as $item) {
            $key = ($item->hsn_sac ?? '').'|'.Decimal::trim($item->gst_rate);
            $rows[$key] ??= ['hsn' => $item->hsn_sac, 'rate' => Decimal::trim($item->gst_rate), 'taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
            $rows[$key]['taxable'] += Money::toPaise($item->taxable_amount);
            $rows[$key]['cgst'] += Money::toPaise($item->cgst_amount);
            $rows[$key]['sgst'] += Money::toPaise($item->sgst_amount);
            $rows[$key]['igst'] += Money::toPaise($item->igst_amount);
        }

        return array_values($rows);
    }

    protected function savedMessage(Invoice $invoice): string
    {
        $label = $invoice->config()['label'];

        return match ($invoice->status()) {
            Voucher::POSTED => "{$label} {$invoice->displayNumber()} posted.",
            Voucher::PENDING => "{$label} submitted for approval.",
            default => "{$label} saved as draft.",
        };
    }

    /** Credit limit and negative stock warnings shown after saving (they never block). */
    protected function warnings(Invoice $invoice): ?string
    {
        return implode(' ', array_filter([$this->creditWarning($invoice), $this->stockWarning($invoice)])) ?: null;
    }

    /** Warn when posting takes an item below zero in its godown. */
    protected function stockWarning(Invoice $invoice): ?string
    {
        if ($invoice->status() !== Voucher::POSTED || ! in_array($invoice->kind, ['sales', 'debit_note'], true)) {
            return null;
        }
        $ids = $invoice->items->pluck('stock_item_id')->filter()->unique()->all();
        $negative = $ids ? app(StockJournalService::class)->negativeItems($ids, $invoice->date->toDateString()) : [];

        return $negative ? 'Stock is now negative for '.implode('; ', $negative).'.' : null;
    }

    /** Warn (do not block) when a customer goes over the credit limit. */
    protected function creditWarning(Invoice $invoice): ?string
    {
        $party = $invoice->party;
        if ($invoice->kind !== 'sales' || $invoice->status() !== Voucher::POSTED || ! $party || ! (float) $party->credit_limit) {
            return null;
        }

        $balance = app(Outstanding::class)->balances([$party->id])[$party->id] ?? 0;
        $limit = Money::toPaise($party->credit_limit);

        return $balance > $limit
            ? "{$party->name} is over the credit limit: balance ".Money::format($balance, paiseInput: true).' against a limit of '.Money::format($limit, paiseInput: true).'.'
            : null;
    }

    protected function kind(Request $request): string
    {
        $kind = (string) $request->input('kind', 'sales');
        abort_unless(array_key_exists($kind, Invoice::kinds()), 404);

        return $kind;
    }

    protected function authorizeKind(Request $request, string $kind, string $action): void
    {
        abort_unless($request->user()->can(Invoice::kindConfig($kind)['module'].'.'.$action), 403);
    }
}
