<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Order;
use App\Models\StockItem;
use App\Services\Billing\OrderService;
use App\Support\CsvExport;
use App\Support\CurrentCompany;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * Sales orders and purchase orders, and the pending orders report.
 */
class OrderController extends Controller
{
    public function __construct(protected OrderService $orders) {}

    public function index(Request $request)
    {
        $kind = $this->kind($request);
        $this->authorizeKind($request, $kind, 'view');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:open,closed,cancelled'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $orders = Order::query()->ofKind($kind)
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('party_name', 'like', "%{$v}%")
                ->orWhere('number', 'like', "%{$v}%")->orWhere('reference', 'like', "%{$v}%")))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
            ->with('items')->orderByDesc('date')->orderByDesc('id')->paginate(50)->withQueryString();

        return view('billing.orders.index', [
            'kind' => $kind,
            'config' => Order::kindConfig($kind),
            'orders' => $orders,
            'labels' => $orders->getCollection()->mapWithKeys(fn ($o) => [$o->id => $this->orders->statusLabel($o)]),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request)
    {
        $kind = $this->kind($request);
        $this->authorizeKind($request, $kind, 'create');
        $order = new Order(['kind' => $kind, 'date' => now()->toDateString()]);
        $input = $this->orders->toInput($order->setRelation('items', collect()));
        if ($request->filled('party')) {
            $input['party_account_id'] = $request->integer('party');
        }

        return $this->form($order, $input);
    }

    public function store(Request $request)
    {
        $kind = $this->kind($request);
        $this->authorizeKind($request, $kind, 'create');
        $order = $this->orders->save(null, $kind, $this->input($request), $request->user());

        return redirect()->route('orders.show', $order)->with('success', $order->config()['label']." {$order->number} saved.");
    }

    public function show(Request $request, Order $order)
    {
        $this->authorizeKind($request, $order->kind, 'view');
        $order->load('items.stockItem.unit', 'party.partyDetails', 'creator', 'statusUser', 'source');
        $progress = $this->orders->progress($order);

        return view('billing.orders.show', [
            'order' => $order,
            'config' => $order->config(),
            'progress' => $progress,
            'label' => $this->orders->statusLabel($order, $progress),
            'invoices' => $this->orders->invoicesFor($order),
            'locked' => $this->orders->referenced($order),
            'conversions' => $order->conversions()->get(),
            'notes' => \App\Models\DeliveryNote::query()->whereHas('items', fn ($q) => $q->whereIn('order_item_id', $order->items->pluck('id')))->orderBy('date')->get(),
        ]);
    }

    public function edit(Request $request, Order $order)
    {
        $this->authorizeKind($request, $order->kind, 'edit');
        abort_unless($order->isOpen(), 403, 'Closed and cancelled orders cannot be altered.');

        return $this->form($order, $this->orders->toInput($order));
    }

    public function update(Request $request, Order $order)
    {
        $this->authorizeKind($request, $order->kind, 'edit');
        $order = $this->orders->save($order, $order->kind, $this->input($request), $request->user());

        return redirect()->route('orders.show', $order)->with('success', $order->config()['label']." {$order->number} saved.");
    }

    public function cancel(Request $request, Order $order)
    {
        $this->authorizeKind($request, $order->kind, 'delete');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->orders->cancel($order, $request->user(), $data['reason']);

        return redirect()->route('orders.show', $order)->with('success', "Order {$order->number} cancelled.");
    }

    public function close(Request $request, Order $order)
    {
        $this->authorizeKind($request, $order->kind, 'edit');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->orders->close($order, $request->user(), $data['reason']);

        return redirect()->route('orders.show', $order)->with('success', "Order {$order->number} closed. Its pending quantity will not be invoiced.");
    }

    public function reopen(Request $request, Order $order)
    {
        $this->authorizeKind($request, $order->kind, 'edit');
        $this->orders->reopen($order, $request->user());

        return redirect()->route('orders.show', $order)->with('success', "Order {$order->number} reopened.");
    }

    /** Turn an open quotation into a sales order. */
    public function convert(Request $request, Order $order)
    {
        abort_unless($order->isQuotation(), 404);
        $target = $order->config()['converts_to'];
        $this->authorizeKind($request, $target, 'create');
        $data = $request->validate(['date' => ['nullable', 'date']]);
        $new = $this->orders->convert($order, $request->user(), $data['date'] ?? null);

        return redirect()->route('orders.show', $new)->with('success', "Quotation {$order->number} converted to ".mb_strtolower($new->config()['label'])." {$new->number}.");
    }

    public function print(Request $request, Order $order)
    {
        return view('pdf.order', $this->printData($request, $order) + ['printable' => true]);
    }

    public function pdf(Request $request, Order $order)
    {
        $data = $this->printData($request, $order);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.order', $data)->setPaper('a4')
            ->download(str_replace('/', '-', $order->number).'.pdf');
    }

    /** Pending lines of open orders, oldest first. */
    public function pending(Request $request)
    {
        $kind = $this->kind($request);
        $this->authorizeKind($request, $kind, 'view');
        abort_if(Order::kindConfig($kind)['quotation'] ?? false, 404);
        $filters = $request->validate(['party' => ['nullable', 'integer'], 'overdue' => ['nullable', 'boolean']]);
        $config = Order::kindConfig($kind);
        $today = now()->toDateString();

        $rows = $this->orders->pending($kind, isset($filters['party']) ? (int) $filters['party'] : null)
            ->when($filters['overdue'] ?? false, fn ($c) => $c->filter(fn ($r) => $r->order->delivery_date && $r->order->delivery_date->toDateString() < $today))
            ->values();

        if (CsvExport::requested($request)) {
            $this->authorize('reports.export');

            return CsvExport::download(str_replace('_', '-', $kind).'s-pending-'.$today.'.csv',
                ['Order', 'Date', 'Delivery by', 'Party', 'Item', 'Ordered', 'Delivered', 'Pending', 'Unit', 'Rate', 'Pending value'],
                $rows->map(fn ($r) => [
                    $r->order->number, $r->order->date->toDateString(), $r->order->delivery_date?->toDateString() ?? '', $r->order->party_name,
                    $r->item->description, $this->qty($r->ordered), $this->qty($r->delivered), $this->qty($r->pending), $r->item->unit ?? '',
                    Money::normalize($r->item->rate), Money::fromPaise($r->pending_value),
                ])->push(['Total', '', '', '', '', '', '', '', '', '', Money::fromPaise($rows->sum('pending_value'))]));
        }

        return view('billing.orders.pending', [
            'kind' => $kind,
            'config' => $config,
            'rows' => $rows,
            'filters' => $filters,
            'today' => $today,
            'parties' => Account::query()->whereIn('id', Order::query()->ofKind($kind)->where('status', Order::OPEN)->distinct()->pluck('party_account_id'))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ------------------------------------------------------------------

    protected function printData(Request $request, Order $order): array
    {
        $this->authorizeKind($request, $order->kind, 'view');
        $order->load('items', 'party.partyDetails');

        return ['order' => $order, 'config' => $order->config(), 'company' => app(CurrentCompany::class)->get()];
    }

    protected function form(Order $order, array $input)
    {
        $config = Order::kindConfig($order->kind);
        if ($old = session()->getOldInput()) {
            $input = array_merge($input, $old);
            $input['items'] = array_values($old['items'] ?? []);
        }
        $company = app(CurrentCompany::class)->get();
        $parties = app(\App\Services\Billing\InvoiceService::class)->partyQuery($config['invoice_kind'])
            ->where('account_type', 'party')->with('partyDetails')->orderBy('name')->get();
        $rateColumn = $config['invoice_kind'] === 'sales' ? 'sales_rate' : 'purchase_rate';

        return view('billing.orders.form', [
            'order' => $order,
            'kind' => $order->kind,
            'config' => $config,
            'input' => $input,
            'parties' => $parties,
            'stockItems' => StockItem::query()->with('unit')->where('is_active', true)->orderBy('name')->get()
                ->map(fn (StockItem $s) => [
                    'id' => $s->id, 'name' => $s->name, 'unit' => $s->unit->symbol, 'hsn' => $s->hsn_sac,
                    'gst' => $s->gst_rate !== null ? Decimal::trim($s->gst_rate) : null,
                    'rate' => $s->{$rateColumn} !== null ? Decimal::trim($s->{$rateColumn}) : null,
                ])->values(),
            'states' => array_values(config('india.states')),
            'gstRates' => config('erp.gst_rates'),
            'gstEnabled' => (bool) $company->setting('enable_gst'),
        ]);
    }

    protected function input(Request $request): array
    {
        $request->validate([
            'date' => ['required', 'date'],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:date'],
            'party_account_id' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:50'],
            'reference_date' => ['nullable', 'date'],
            'place_of_supply' => ['nullable', 'string', 'in:'.implode(',', array_values(config('india.states')))],
            'narration' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'max:200'],
            'items.*.stock_item_id' => ['nullable', 'integer'],
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
            'delivery_date.after_or_equal' => 'This date cannot be before the document date.',
        ]);

        $data = $request->only(['date', 'delivery_date', 'party_account_id', 'reference', 'reference_date', 'place_of_supply', 'narration', 'terms']);
        $data['items'] = array_values($request->input('items', []));

        return $data;
    }

    protected function qty(int $milli): string
    {
        return Decimal::trim(Decimal::fromScaled($milli, 3));
    }

    protected function kind(Request $request): string
    {
        $kind = (string) $request->input('kind', 'sales_order');
        abort_unless(array_key_exists($kind, Order::kinds()), 404);

        return $kind;
    }

    protected function authorizeKind(Request $request, string $kind, string $action): void
    {
        abort_unless($request->user()->can(Order::kindConfig($kind)['module'].'.'.$action), 403);
    }
}
