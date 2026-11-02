<?php

namespace App\Services\Inventory;

use App\Models\AuditLog;
use App\Models\Godown;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\StockItem;
use App\Models\StockJournal;
use App\Models\StockJournalLine;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods in transit between godowns.
 *
 * A dispatch is a godown transfer saved "in transit": the stock leaves the source godown
 * at once and sits in the company's transit godown, so it stays in closing stock but is
 * in neither godown. Receiving it posts a transfer from the transit godown to the
 * destination; any quantity that never arrives is written off from the transit godown by
 * a shortage adjustment. Both point back at the dispatch, line by line, so what is still
 * in transit is the dispatched quantity less what was received or written off.
 *
 * A purchase bill booked before the goods arrive works the same way: its stock lines go into
 * the transit godown, bound for the godown chosen on each line, and are received against the
 * bill's lines.
 */
class GoodsInTransit
{
    public function __construct(protected StockJournalService $journals, protected StockLedger $ledger) {}

    /**
     * Each item line of a dispatch with quantities in thousandths.
     *
     * @return Collection<int, object{line: StockJournalLine, dispatched: int, received: int, short: int, pending: int}>
     */
    public function lines(StockJournal $dispatch, ?string $asOf = null): Collection
    {
        $dispatch->loadMissing('lines.item.unit');
        // The lines that left the source godown; their pairs went into transit.
        $out = $dispatch->lines->where('direction', 'out')->values();
        $settled = StockJournalLine::query()->whereIn('transit_line_id', $out->pluck('id'))->where('direction', 'out')
            ->whereHas('journal', fn ($q) => $q->where('status', 'posted')->when($asOf, fn ($q) => $q->where('date', '<=', $asOf)))
            ->with('journal:id,purpose')->get()->groupBy('transit_line_id');

        return $out->map(function (StockJournalLine $line) use ($settled) {
            $rows = $settled->get($line->id, collect());
            $received = $rows->filter(fn ($r) => $r->journal->purpose === 'transfer')->sum(fn ($r) => Decimal::toScaled($r->quantity, 3));
            $short = $rows->filter(fn ($r) => $r->journal->purpose !== 'transfer')->sum(fn ($r) => Decimal::toScaled($r->quantity, 3));
            $dispatched = Decimal::toScaled($line->quantity, 3);

            return (object) ['line' => $line, 'item' => $line->item, 'dispatched' => $dispatched, 'received' => $received, 'short' => $short, 'pending' => $dispatched - $received - $short];
        });
    }

    /**
     * Each stock line of a purchase bill booked in transit, with quantities in thousandths.
     *
     * @return Collection<int, object{line: InvoiceItem, item: StockItem, dispatched: int, received: int, short: int, pending: int}>
     */
    public function invoiceLines(Invoice $invoice, ?string $asOf = null): Collection
    {
        $invoice->loadMissing('items.stockItem.unit', 'items.transitTo');
        $lines = $invoice->items->whereNotNull('transit_to_godown_id')->values();
        $settled = StockJournalLine::query()->whereIn('transit_invoice_item_id', $lines->pluck('id'))->where('direction', 'out')
            ->whereHas('journal', fn ($q) => $q->where('status', 'posted')->when($asOf, fn ($q) => $q->where('date', '<=', $asOf)))
            ->with('journal:id,purpose')->get()->groupBy('transit_invoice_item_id');

        return $lines->map(function (InvoiceItem $line) use ($settled) {
            $rows = $settled->get($line->id, collect());
            $received = $rows->filter(fn ($r) => $r->journal->purpose === 'transfer')->sum(fn ($r) => Decimal::toScaled($r->quantity, 3));
            $short = $rows->filter(fn ($r) => $r->journal->purpose !== 'transfer')->sum(fn ($r) => Decimal::toScaled($r->quantity, 3));
            $dispatched = Decimal::toScaled($line->quantity, 3);

            return (object) ['line' => $line, 'item' => $line->stockItem, 'dispatched' => $dispatched, 'received' => $received, 'short' => $short, 'pending' => $dispatched - $received - $short];
        });
    }

    /**
     * Dispatches and purchase bills with goods still in transit on a date, with the pending lines
     * and their value: a dispatch's at the item's cost on that date, a bill's at its own rate.
     *
     * @return array{rows: Collection, total: int}
     */
    public function pending(string $asOf): array
    {
        $dispatches = StockJournal::query()->whereNotNull('transit_to_godown_id')->where('status', 'posted')->where('date', '<=', $asOf)
            ->with('lines.item.unit', 'lines.godown', 'transitTo')->orderBy('date')->orderBy('id')->get();
        $rows = $dispatches->map(fn (StockJournal $d) => (object) [
            'dispatch' => $d, 'number' => $d->number, 'date' => $d->date, 'url' => route('stock-journals.show', $d), 'kind' => 'Transfer',
            'lines' => $this->lines($d, $asOf)->filter(fn ($l) => $l->pending > 0)
                ->each(function ($l) use ($d) { $l->from = $l->line->godown?->name; $l->to = $d->transitTo?->name; })->values(),
        ]);
        $bills = Invoice::query()->where('kind', 'purchase')->where('in_transit', true)->where('date', '<=', $asOf)
            ->whereHas('voucher', fn ($q) => $q->where('status', Voucher::POSTED))->with('voucher')->orderBy('date')->orderBy('id')->get();
        $rows = $rows->concat($bills->map(fn (Invoice $i) => (object) [
            'dispatch' => null, 'number' => $i->displayNumber(), 'date' => $i->date, 'url' => route('invoices.show', $i), 'kind' => 'Purchase',
            'lines' => $this->invoiceLines($i, $asOf)->filter(fn ($l) => $l->pending > 0)
                ->each(function ($l) use ($i) { $l->from = $i->party_name; $l->to = $l->line->transitTo?->name; })->values(),
        ]))->filter(fn ($r) => $r->lines->isNotEmpty())->sortBy(fn ($r) => $r->date->toDateString())->values();

        $itemIds = $rows->flatMap(fn ($r) => $r->lines->pluck('line.stock_item_id'))->unique()->values();
        $positions = $itemIds->isEmpty() ? collect() : $this->ledger->position($asOf, StockItem::query()->with('unit', 'group')->whereIn('id', $itemIds)->get());
        $total = 0;
        foreach ($rows as $r) {
            $r->value = 0;
            foreach ($r->lines as $l) {
                // A bill's goods are valued at what they cost on the bill; a transfer's at the item's cost.
                $l->value = $r->dispatch
                    ? Decimal::mulDiv($l->pending, (int) ($positions[$l->line->stock_item_id]->rate ?? 0), 1000)
                    : Decimal::mulDiv($l->pending, Money::toPaise($l->line->taxable_amount), $l->dispatched);
                $r->value += $l->value;
            }
            $total += $r->value;
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Receive goods against a dispatch, and write off what will not arrive.
     *
     * @param  array{date: string, narration?: ?string, lines: array<int, array{received?: string, short?: string}>}  $data  keyed by dispatch line id
     * @return array{0: ?StockJournal, 1: ?StockJournal} the receipt and the shortage journal
     */
    public function receive(StockJournal $dispatch, array $data, User $user): array
    {
        if (! $dispatch->isDispatch() || ! $dispatch->isPosted()) {
            throw ValidationException::withMessages(['receive' => 'Only a posted dispatch of goods in transit can be received.']);
        }
        $date = $this->journals->validDate($data['date'] ?? null);
        if ($date < $dispatch->date->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Goods cannot be received before they were dispatched on '.$dispatch->date->format('d M Y').'.']);
        }
        [$errors, $receive, $short] = $this->quantities($this->lines($dispatch), $data, '_line');
        if (! $errors && ! $receive && ! $short) {
            $errors['receive'] = 'Enter what was received or is short on at least one line.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($dispatch, $data, $user, $date, $receive, $short) {
            $transit = Godown::transit();
            $note = trim((string) ($data['narration'] ?? ''));
            $receipt = $receive ? $this->post([
                'date' => $date, 'purpose' => 'transfer', 'from_godown_id' => $transit->id, 'to_godown_id' => $dispatch->transit_to_godown_id,
                'narration' => trim("Received against dispatch {$dispatch->number}. {$note}"), 'lines' => $receive,
            ], $user, ['dispatch_id' => $dispatch->id], 2) : null;
            $shortage = $short ? $this->post([
                'date' => $date, 'purpose' => 'adjustment',
                'narration' => trim("Shortage in transit against dispatch {$dispatch->number}. {$note}"),
                'lines' => array_map(fn ($l) => $l + ['godown_id' => $transit->id], $short),
            ], $user, ['dispatch_id' => $dispatch->id], 1) : null;
            AuditLog::record('updated', $dispatch, null, null, "Goods in transit {$dispatch->number}: "
                .implode(', ', array_filter([$receipt ? "received by {$receipt->number}" : null, $shortage ? "shortage written off by {$shortage->number}" : null])));

            return [$receipt, $shortage];
        });
    }

    /**
     * Receive goods bought in transit on a purchase bill into the godowns chosen on its lines,
     * and write off what will not arrive. One receipt is posted for each destination godown.
     *
     * @param  array{date: string, narration?: ?string, lines: array<int, array{received?: string, short?: string}>}  $data  keyed by bill line id
     * @return array{0: Collection<int, StockJournal>, 1: ?StockJournal} the receipts and the shortage journal
     */
    public function receiveInvoice(Invoice $invoice, array $data, User $user): array
    {
        if ($invoice->kind !== 'purchase' || ! $invoice->in_transit || $invoice->status() !== Voucher::POSTED) {
            throw ValidationException::withMessages(['receive' => 'Only a posted purchase bill with goods in transit can be received.']);
        }
        $date = $this->journals->validDate($data['date'] ?? null);
        if ($date < $invoice->date->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Goods cannot be received before the bill date, '.$invoice->date->format('d M Y').'.']);
        }
        $lines = $this->invoiceLines($invoice);
        [$errors, $receive, $short] = $this->quantities($lines, $data, '_item');
        if (! $errors && ! $receive && ! $short) {
            $errors['receive'] = 'Enter what was received or is short on at least one line.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $destination = $lines->mapWithKeys(fn ($l) => [$l->line->id => $l->line->transit_to_godown_id]);

        return DB::transaction(function () use ($invoice, $data, $user, $date, $receive, $short, $destination) {
            $transit = Godown::transit();
            $note = trim((string) ($data['narration'] ?? ''));
            $number = $invoice->displayNumber();
            $receipts = collect($receive)->groupBy(fn ($l) => $destination[$l['_item']])->map(fn ($group, $to) => $this->post([
                'date' => $date, 'purpose' => 'transfer', 'from_godown_id' => $transit->id, 'to_godown_id' => (int) $to,
                'narration' => trim("Received against purchase {$number} from {$invoice->party_name}. {$note}"), 'lines' => $group->all(),
            ], $user, ['dispatch_invoice_id' => $invoice->id], 2, '_item', 'transit_invoice_item_id'))->values();
            $shortage = $short ? $this->post([
                'date' => $date, 'purpose' => 'adjustment',
                'narration' => trim("Shortage in transit against purchase {$number} from {$invoice->party_name}. {$note}"),
                'lines' => array_map(fn ($l) => $l + ['godown_id' => $transit->id], $short),
            ], $user, ['dispatch_invoice_id' => $invoice->id], 1, '_item', 'transit_invoice_item_id') : null;
            AuditLog::record('updated', $invoice, null, null, "Goods in transit on purchase {$number}: "
                .implode(', ', array_filter([$receipts->isNotEmpty() ? 'received by '.$receipts->pluck('number')->implode(', ') : null, $shortage ? "shortage written off by {$shortage->number}" : null])));

            return [$receipts, $shortage];
        });
    }

    /**
     * Read the received and short quantities entered for each pending line.
     *
     * @return array{0: array<string, string>, 1: list<array>, 2: list<array>} errors, receipt lines, shortage lines
     */
    protected function quantities(Collection $lines, array $data, string $link): array
    {
        $errors = [];
        $receive = [];
        $short = [];
        foreach ($lines as $n => $l) {
            $item = $l->item;
            $label = 'Line '.($n + 1)." ({$item->name})";
            $input = $data['lines'][$l->line->id] ?? [];
            $q = [];
            foreach (['received', 'short'] as $field) {
                $value = str_replace(',', '', trim((string) ($input[$field] ?? '')));
                if ($value === '') {
                    $q[$field] = 0;

                    continue;
                }
                $places = $item->unit->decimal_places;
                if (! preg_match('/^\d{1,7}(\.\d{1,3})?$/', $value) || (str_contains($value, '.') && strlen(rtrim(explode('.', $value)[1], '0')) > $places)) {
                    $errors["lines.{$l->line->id}.{$field}"] = "{$label}: enter a quantity with at most {$places} decimal places.";
                    $q[$field] = 0;

                    continue;
                }
                $q[$field] = Decimal::toScaled($value, 3);
            }
            if ($q['received'] + $q['short'] > $l->pending) {
                $errors["lines.{$l->line->id}.received"] = "{$label}: only ".StockLedger::qty($l->pending, $item->unit).' is still in transit.';
            } elseif ($item->tracking === 'serial' && ($q['short'] > 0 || ! in_array($q['received'], [0, $l->pending], true))) {
                $errors["lines.{$l->line->id}.received"] = "{$label}: items with serial numbers are received in full. Receive them and adjust any missing serials at the destination.";
            }
            $base = ['stock_item_id' => $item->id, 'batch_no' => $l->line->batch_no, 'serials' => $l->line->serials, $link => $l->line->id];
            if ($q['received'] > 0) {
                $receive[] = $base + ['quantity' => Decimal::fromScaled($q['received'], 3)];
            }
            if ($q['short'] > 0) {
                $short[] = $base + ['quantity' => Decimal::fromScaled($q['short'], 3), 'direction' => 'out', 'rate' => ''];
            }
        }

        return [$errors, $receive, $short];
    }

    /** Post a receipt or shortage journal and tie each of its lines to the line it settles. */
    protected function post(array $data, User $user, array $extra, int $perRow, string $link = '_line', string $column = 'transit_line_id'): StockJournal
    {
        $links = array_column($data['lines'], $link);
        $data['lines'] = array_map(fn ($l) => array_diff_key($l, [$link => true]), $data['lines']);
        $journal = $this->journals->createInternal($data, $user, $extra);
        foreach ($journal->lines()->orderBy('id')->get()->values() as $i => $line) {
            $line->update([$column => $links[intdiv($i, $perRow)]]);
        }

        return $journal->fresh('lines');
    }
}
