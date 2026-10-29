<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\DeliveryNote;
use App\Models\Godown;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockItem;
use App\Services\Billing\InvoiceService;
use App\Services\Inventory\BatchTracker;
use App\Services\Inventory\DeliveryNoteService;
use App\Services\Inventory\StockJournalService;
use App\Services\Inventory\StockLedger;
use App\Support\CsvExport;
use App\Support\CurrentCompany;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Delivery notes and goods receipt notes, and the "not yet invoiced" report.
 */
class DeliveryNoteController extends Controller
{
    public function __construct(protected DeliveryNoteService $notes) {}

    public function index(Request $request)
    {
        $kind = $this->kind($request);
        $this->authorizeKind($request, $kind, 'view');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $notes = DeliveryNote::query()->ofKind($kind)
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('party_name', 'like', "%{$v}%")
                ->orWhere('number', 'like', "%{$v}%")->orWhere('reference', 'like', "%{$v}%")))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
            ->with('items')->orderByDesc('date')->orderByDesc('id')->paginate(50)->withQueryString();

        return view('inventory.notes.index', [
            'kind' => $kind,
            'config' => DeliveryNote::kindConfig($kind),
            'notes' => $notes,
            'labels' => $notes->getCollection()->mapWithKeys(fn ($n) => [$n->id => $this->notes->statusLabel($n)]),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request)
    {
        $kind = $this->kind($request);
        $this->authorizeKind($request, $kind, 'create');
        $config = DeliveryNote::kindConfig($kind);
        $input = ['date' => now()->toDateString(), 'lines' => [[], [], []]];

        if ($request->filled('order')) {
            $order = Order::query()->findOrFail($request->integer('order'));
            abort_unless($order->kind === $config['order_kind'], 404);
            try {
                $input = array_merge($input, $this->notes->orderInput($order));
            } catch (\Illuminate\Validation\ValidationException $e) {
                return redirect()->route('orders.show', $order)->with('error', collect($e->errors())->flatten()->first());
            }
        } elseif ($request->filled('party')) {
            $input['party_account_id'] = $request->integer('party');
        }
        if ($old = session()->getOldInput()) {
            $input = array_merge($input, $old);
            $input['lines'] = array_values($old['lines'] ?? []);
        }

        $items = StockItem::query()->with('unit')->where('is_active', true)->orderBy('name')->get();
        $godowns = Godown::query()->usable()->orderByDesc('is_default')->orderBy('name')->get();

        return view('inventory.notes.form', [
            'kind' => $kind,
            'config' => $config,
            'input' => $input,
            'parties' => app(InvoiceService::class)->partyQuery($config['invoice_kind'])->where('account_type', 'party')->orderBy('name')->get(['id', 'name']),
            'items' => $items,
            'godowns' => $godowns,
            'anyTracked' => $items->contains(fn ($i) => $i->tracking !== 'none'),
            'batchOptions' => $config['direction'] === 'out' ? $this->batchOptions($items, $godowns) : [],
            'orderLines' => OrderItem::query()->with('order')->whereIn('id', array_filter(array_column($input['lines'], 'order_item_id')))->get()
                ->mapWithKeys(fn ($oi) => [$oi->id => $oi->order->number.' · line '.$oi->line_no])->all(),
        ]);
    }

    public function store(Request $request)
    {
        $kind = $this->kind($request);
        $this->authorizeKind($request, $kind, 'create');
        $request->validate([
            'date' => ['required', 'date'],
            'party_account_id' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:50'],
            'reference_date' => ['nullable', 'date'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'max:200'],
            'lines.*' => ['array'],
        ], ['party_account_id.required' => 'Select the party.']);

        $note = $this->notes->create($kind, $request->only(['date', 'party_account_id', 'reference', 'reference_date', 'narration', 'lines']), $request->user());
        $negative = $note->config()['direction'] === 'out'
            ? app(StockJournalService::class)->negativeItems($note->items->pluck('stock_item_id')->unique()->all(), $note->date->toDateString())
            : [];

        return redirect()->route('notes.show', $note)
            ->with('success', $note->config()['label']." {$note->number} saved.")
            ->with('warning', $negative ? 'Stock is now negative for '.implode('; ', $negative).'.' : null);
    }

    public function show(Request $request, DeliveryNote $note)
    {
        $this->authorizeKind($request, $note->kind, 'view');
        $note->load('items.item.unit', 'items.godown', 'items.orderItem.order', 'creator', 'party.partyDetails');
        $progress = $this->notes->progress($note);

        return view('inventory.notes.show', [
            'note' => $note,
            'config' => $note->config(),
            'progress' => $progress,
            'label' => $this->notes->statusLabel($note, $progress),
            'invoices' => $this->notes->invoicesFor($note),
        ]);
    }

    public function cancel(Request $request, DeliveryNote $note)
    {
        $this->authorizeKind($request, $note->kind, 'delete');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->notes->cancel($note, $request->user(), $data['reason']);

        return redirect()->route('notes.show', $note)->with('success', "{$note->number} cancelled. It no longer moves stock.");
    }

    public function print(Request $request, DeliveryNote $note)
    {
        return view('pdf.note', $this->printData($request, $note) + ['printable' => true]);
    }

    public function pdf(Request $request, DeliveryNote $note)
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.note', $this->printData($request, $note))->setPaper('a4')
            ->download(str_replace('/', '-', $note->number).'.pdf');
    }

    /** Lines of posted notes not yet invoiced. */
    public function pending(Request $request)
    {
        $kind = $this->kind($request);
        $this->authorizeKind($request, $kind, 'view');
        $filters = $request->validate(['party' => ['nullable', 'integer']]);
        $config = DeliveryNote::kindConfig($kind);
        $rows = $this->notes->pending($kind, isset($filters['party']) ? (int) $filters['party'] : null);
        $qty = fn ($m) => Decimal::trim(Decimal::fromScaled($m, 3));

        if (CsvExport::requested($request)) {
            $this->authorize('reports.export');

            return CsvExport::download(str_replace('_', '-', $kind).'s-not-invoiced-'.now()->toDateString().'.csv',
                ['Note', 'Date', 'Party', 'Item', 'Quantity', 'Invoiced', 'Pending', 'Unit', 'Rate', 'Value'],
                $rows->map(fn ($r) => [
                    $r->note->number, $r->note->date->toDateString(), $r->note->party_name, $r->line->description,
                    $qty($r->quantity), $qty($r->invoiced), $qty($r->pending), $r->line->unit ?? '', Money::normalize($r->line->rate), Money::fromPaise($r->value),
                ])->push(['Total', '', '', '', '', '', '', '', '', Money::fromPaise($rows->sum('value'))]));
        }

        return view('inventory.notes.pending', [
            'kind' => $kind,
            'config' => $config,
            'rows' => $rows,
            'filters' => $filters,
            'parties' => Account::query()->whereIn('id', DeliveryNote::query()->ofKind($kind)->where('status', 'posted')->distinct()->pluck('party_account_id'))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ------------------------------------------------------------------

    protected function printData(Request $request, DeliveryNote $note): array
    {
        $this->authorizeKind($request, $note->kind, 'view');
        $note->load('items.godown', 'items.orderItem.order', 'party.partyDetails');

        return ['note' => $note, 'config' => $note->config(), 'company' => app(CurrentCompany::class)->get()];
    }

    /** Batches in stock per item for the batch picker: [item id => [[batch, label]]]. */
    protected function batchOptions($items, $godowns): array
    {
        $tracked = $items->where('tracking', 'batch')->keyBy('id');
        if ($tracked->isEmpty()) {
            return [];
        }
        $names = $godowns->pluck('name', 'id');
        $out = [];
        foreach (app(BatchTracker::class)->batches(null, $tracked->keys()->all())->filter(fn ($b) => $b->quantity > 0) as $b) {
            $out[$b->stock_item_id][] = ['batch' => $b->batch_no, 'label' => $b->batch_no.' · '.StockLedger::qty($b->quantity, $tracked[$b->stock_item_id]->unit)
                .($b->expiry_date ? ' · exp '.Carbon::parse($b->expiry_date)->format('d-m-Y') : '').($names->count() > 1 ? ' · '.($names[$b->godown_id] ?? '') : '')];
        }

        return $out;
    }

    protected function kind(Request $request): string
    {
        $kind = (string) $request->input('kind', 'delivery_note');
        abort_unless(array_key_exists($kind, DeliveryNote::kinds()), 404);

        return $kind;
    }

    protected function authorizeKind(Request $request, string $kind, string $action): void
    {
        abort_unless($request->user()->can(DeliveryNote::kindConfig($kind)['module'].'.'.$action), 403);
    }
}
