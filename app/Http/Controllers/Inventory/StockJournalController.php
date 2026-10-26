<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Godown;
use App\Models\StockItem;
use App\Models\StockJournal;
use App\Services\Inventory\StockJournalService;
use Illuminate\Http\Request;

class StockJournalController extends Controller
{
    public function __construct(protected StockJournalService $journals) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'purpose' => ['nullable', 'in:'.implode(',', array_keys(StockJournal::PURPOSES))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $journals = StockJournal::query()->with('lines.item')
            ->when($filters['purpose'] ?? null, fn ($q, $v) => $q->where('purpose', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('inventory.journals.index', compact('journals', 'filters'));
    }

    public function create(Request $request)
    {
        $purpose = array_key_exists((string) $request->query('purpose'), StockJournal::PURPOSES) ? $request->query('purpose') : 'transfer';

        return view('inventory.journals.form', [
            'purpose' => old('purpose', $purpose),
            'items' => StockItem::query()->with('unit')->where('is_active', true)->orderBy('name')->get(),
            'godowns' => Godown::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'purpose' => ['required', 'string'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'from_godown_id' => ['nullable', 'integer'],
            'to_godown_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'max:200'],
            'lines.*' => ['array'],
        ]);

        $journal = $this->journals->create($data, $request->user());
        $negative = $this->journals->negativeItems($journal->lines->pluck('stock_item_id')->unique()->all(), $journal->date->toDateString());

        return redirect()->route('stock-journals.show', $journal)
            ->with('success', "Stock journal {$journal->number} saved.")
            ->with('warning', $negative ? 'Stock is now negative for '.implode('; ', $negative).'.' : null);
    }

    public function show(StockJournal $stockJournal)
    {
        $stockJournal->load('lines.item.unit', 'lines.godown', 'creator');

        return view('inventory.journals.show', ['journal' => $stockJournal]);
    }

    public function cancel(Request $request, StockJournal $stockJournal)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->journals->cancel($stockJournal, $request->user(), $data['reason']);

        return back()->with('success', "Stock journal {$stockJournal->number} cancelled.");
    }
}
