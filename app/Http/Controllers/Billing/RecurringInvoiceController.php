<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringVoucher;
use App\Services\Billing\RecurringInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Recurring sales and purchase invoices. Rights follow the invoice kind: sales.* for
 * sales, purchases.* for purchases.
 */
class RecurringInvoiceController extends Controller
{
    public function __construct(protected RecurringInvoiceService $recurring) {}

    public function index(Request $request)
    {
        $kinds = $this->kinds($request, 'view');
        abort_if($kinds === [], 403);
        $today = now()->toDateString();
        $query = RecurringInvoice::query()->whereIn('kind', $kinds);

        return view('billing.recurring.index', [
            'items' => (clone $query)->with('party')->withCount(['runs as failed_count' => fn ($q) => $q->where('status', 'failed')])
                ->orderByDesc('is_active')->orderBy('next_date')->orderBy('name')->get(),
            'dueCount' => (clone $query)->whereIn('kind', $this->kinds($request, 'create'))->where('is_active', true)->whereNotNull('next_date')->where('next_date', '<=', $today)->count(),
            'today' => $today,
        ]);
    }

    public function create(Request $request)
    {
        $invoice = Invoice::query()->findOrFail($request->integer('invoice'));
        $this->authorizeKind($request, $invoice->kind, 'create');
        [, $why] = $this->recurring->templateFrom($invoice);
        if ($why) {
            return redirect()->route('invoices.show', $invoice)->with('error', $why);
        }
        $start = $invoice->date->copy()->addMonthNoOverflow();

        return view('billing.recurring.form', [
            'recurring' => new RecurringInvoice([
                'name' => mb_substr($invoice->party_name.' '.$start->format('M Y').' onwards', 0, 100), 'frequency' => 'monthly', 'mode' => 'post', 'is_active' => true,
                'start_date' => $start->toDateString(),
                'due_days' => $invoice->due_date ? (int) $invoice->date->diffInDays($invoice->due_date) : null,
            ]),
            'invoice' => $invoice,
        ]);
    }

    public function store(Request $request)
    {
        $invoice = Invoice::query()->findOrFail($request->integer('invoice_id'));
        $this->authorizeKind($request, $invoice->kind, 'create');
        $recurring = $this->recurring->save(null, $invoice, $this->validated($request, null), $request->user());

        return redirect()->route('recurring-invoices.show', $recurring)
            ->with('success', "Recurring invoice \"{$recurring->name}\" saved. The first invoice is due on {$recurring->next_date?->format('d M Y')}.");
    }

    public function show(Request $request, RecurringInvoice $recurringInvoice)
    {
        $this->authorizeKind($request, $recurringInvoice->kind, 'view');
        $recurringInvoice->load('party', 'source', 'creator');
        $upcoming = [];
        for ($d = $recurringInvoice->is_active ? $recurringInvoice->next_date : null; $d && count($upcoming) < 6; $d = $recurringInvoice->dateAfter($d)) {
            if ($recurringInvoice->end_date && $d->gt($recurringInvoice->end_date)) {
                break;
            }
            $upcoming[] = $d->toDateString();
        }

        return view('billing.recurring.show', [
            'recurring' => $recurringInvoice,
            'runs' => $recurringInvoice->runs()->with('invoice.voucher', 'runner')->limit(100)->get(),
            'upcoming' => $upcoming,
            'accounts' => \App\Models\Account::query()->whereIn('id', collect($recurringInvoice->template['items'] ?? [])->pluck('account_id')->filter())->pluck('name', 'id'),
            'stockItems' => \App\Models\StockItem::query()->whereIn('id', collect($recurringInvoice->template['items'] ?? [])->pluck('stock_item_id')->filter())->pluck('name', 'id'),
        ]);
    }

    public function edit(Request $request, RecurringInvoice $recurringInvoice)
    {
        $this->authorizeKind($request, $recurringInvoice->kind, 'create');

        return view('billing.recurring.form', ['recurring' => $recurringInvoice, 'invoice' => null]);
    }

    public function update(Request $request, RecurringInvoice $recurringInvoice)
    {
        $this->authorizeKind($request, $recurringInvoice->kind, 'create');
        $source = $request->filled('invoice_id') ? Invoice::query()->where('kind', $recurringInvoice->kind)->findOrFail($request->integer('invoice_id')) : null;
        $this->recurring->save($recurringInvoice, $source, $this->validated($request, $recurringInvoice), $request->user());

        return redirect()->route('recurring-invoices.show', $recurringInvoice)->with('success', 'Recurring invoice saved.');
    }

    public function destroy(Request $request, RecurringInvoice $recurringInvoice)
    {
        $this->authorizeKind($request, $recurringInvoice->kind, 'delete');
        $recurringInvoice->delete();

        return redirect()->route('recurring-invoices.index')->with('success', "Recurring invoice \"{$recurringInvoice->name}\" deleted. Invoices already made are kept.");
    }

    public function run(Request $request, RecurringInvoice $recurringInvoice)
    {
        $this->authorizeKind($request, $recurringInvoice->kind, 'create');
        $runs = $this->recurring->run($recurringInvoice, $request->user());
        $made = $runs->where('status', 'created')->count();
        $failed = $runs->firstWhere('status', 'failed');

        return redirect()->route('recurring-invoices.show', $recurringInvoice)
            ->with($failed ? 'error' : 'success', $made.' '.str('invoice')->plural($made).' made.'.($failed ? " The one for {$failed->date->format('d M Y')} failed: {$failed->message}" : ''));
    }

    public function runAll(Request $request)
    {
        $kinds = $this->kinds($request, 'create');
        abort_if($kinds === [], 403);
        $made = $failed = 0;
        $due = RecurringInvoice::query()->whereIn('kind', $kinds)->where('is_active', true)->whereNotNull('next_date')->where('next_date', '<=', now()->toDateString())->get();
        foreach ($due as $recurring) {
            $runs = $this->recurring->run($recurring, $request->user());
            $made += $runs->where('status', 'created')->count();
            $failed += $runs->where('status', 'failed')->count();
        }

        return redirect()->route('recurring-invoices.index')->with($failed ? 'warning' : 'success', "{$made} ".str('invoice')->plural($made).' made'.($failed ? ", {$failed} failed. Open the schedules marked failed to see why." : '.'));
    }

    protected function validated(Request $request, ?RecurringInvoice $recurring): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('recurring_invoices')->where('company_id', app(\App\Support\CurrentCompany::class)->id())->ignore($recurring?->id)],
            'frequency' => ['required', Rule::in(array_keys(RecurringVoucher::FREQUENCIES))],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date'],
            'next_date' => ['nullable', 'date'],
            'due_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'mode' => ['required', 'in:post,draft'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => $recurring ? $request->boolean('is_active') : true];
    }

    /** Invoice kinds that can recur and that the user may $action. */
    protected function kinds(Request $request, string $action): array
    {
        return array_values(array_filter(RecurringInvoice::KINDS, fn ($k) => $request->user()->can(Invoice::kindConfig($k)['module'].'.'.$action)));
    }

    protected function authorizeKind(Request $request, string $kind, string $action): void
    {
        abort_unless($request->user()->can(Invoice::kindConfig($kind)['module'].'.'.$action), 403);
    }
}
