<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\StockJournal;
use App\Services\Inventory\GoodsInTransit;
use App\Services\Inventory\StockLedger;
use App\Support\CsvExport;
use App\Support\Money;
use Illuminate\Http\Request;

/** Goods dispatched between godowns or bought and not yet received, and receiving them. */
class GoodsInTransitController extends Controller
{
    public function __construct(protected GoodsInTransit $transit) {}

    public function index(Request $request)
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('date')) ? $request->input('date') : now()->toDateString();
        $report = $this->transit->pending($date);

        if (CsvExport::requested($request)) {
            $rows = $report['rows']->flatMap(fn ($r) => $r->lines->map(fn ($l) => [
                $r->kind, $r->number, $r->date->format('d-m-Y'), $l->from, $l->to,
                $l->item->name, $l->line->batch_no, StockLedger::qty($l->dispatched), StockLedger::qty($l->received + $l->short), StockLedger::qty($l->pending),
                $l->item->unit?->symbol, Money::fromPaise($l->value),
            ]));

            return CsvExport::download("goods-in-transit-{$date}.csv",
                ['Type', 'Document', 'Date', 'From', 'To', 'Item', 'Batch', 'Dispatched', 'Received or short', 'In transit', 'Unit', 'Value'],
                $rows->push(['Total', '', '', '', '', '', '', '', '', '', '', Money::fromPaise($report['total'])]));
        }

        return view('inventory.reports.transit', $report + ['date' => $date]);
    }

    public function receive(Request $request, StockJournal $stockJournal)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array'],
            'lines.*' => ['array'],
        ]);
        [$receipt, $shortage] = $this->transit->receive($stockJournal, $data, $request->user());

        return redirect()->route('stock-journals.show', $stockJournal)->with('success', implode(' ', array_filter([
            $receipt ? "Received as {$receipt->number}." : null,
            $shortage ? "Shortage written off as {$shortage->number}." : null,
        ])));
    }

    /** Receive goods bought in transit on a purchase bill. */
    public function receiveInvoice(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array'],
            'lines.*' => ['array'],
        ]);
        [$receipts, $shortage] = $this->transit->receiveInvoice($invoice, $data, $request->user());

        return redirect()->route('invoices.show', $invoice)->with('success', implode(' ', array_filter([
            $receipts->isNotEmpty() ? 'Received as '.$receipts->pluck('number')->implode(', ').'.' : null,
            $shortage ? "Shortage written off as {$shortage->number}." : null,
        ])));
    }
}
