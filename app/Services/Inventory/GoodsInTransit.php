<?php

namespace App\Services\Inventory;

use App\Models\AuditLog;
use App\Models\Godown;
use App\Models\StockItem;
use App\Models\StockJournal;
use App\Models\StockJournalLine;
use App\Models\User;
use App\Support\Decimal;
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

            return (object) ['line' => $line, 'dispatched' => $dispatched, 'received' => $received, 'short' => $short, 'pending' => $dispatched - $received - $short];
        });
    }

    /**
     * Dispatches with goods still in transit on a date, with the pending lines and their value at
     * the item's cost on that date.
     *
     * @return array{rows: Collection, total: int}
     */
    public function pending(string $asOf): array
    {
        $dispatches = StockJournal::query()->whereNotNull('transit_to_godown_id')->where('status', 'posted')->where('date', '<=', $asOf)
            ->with('lines.item.unit', 'lines.godown', 'transitTo')->orderBy('date')->orderBy('id')->get();
        $rows = $dispatches->map(fn (StockJournal $d) => (object) ['dispatch' => $d, 'lines' => $this->lines($d, $asOf)->filter(fn ($l) => $l->pending > 0)->values()])
            ->filter(fn ($r) => $r->lines->isNotEmpty())->values();

        $itemIds = $rows->flatMap(fn ($r) => $r->lines->pluck('line.stock_item_id'))->unique()->values();
        $positions = $itemIds->isEmpty() ? collect() : $this->ledger->position($asOf, StockItem::query()->with('unit', 'group')->whereIn('id', $itemIds)->get());
        $total = 0;
        foreach ($rows as $r) {
            $r->value = 0;
            foreach ($r->lines as $l) {
                $l->value = Decimal::mulDiv($l->pending, (int) ($positions[$l->line->stock_item_id]->rate ?? 0), 1000);
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
        $errors = [];
        $receive = [];
        $short = [];
        foreach ($this->lines($dispatch) as $n => $l) {
            $item = $l->line->item;
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
            $base = ['stock_item_id' => $item->id, 'batch_no' => $l->line->batch_no, 'serials' => $l->line->serials, '_line' => $l->line->id];
            if ($q['received'] > 0) {
                $receive[] = $base + ['quantity' => Decimal::fromScaled($q['received'], 3)];
            }
            if ($q['short'] > 0) {
                $short[] = $base + ['quantity' => Decimal::fromScaled($q['short'], 3), 'direction' => 'out', 'rate' => ''];
            }
        }
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
            ], $user, $dispatch, 2) : null;
            $shortage = $short ? $this->post([
                'date' => $date, 'purpose' => 'adjustment',
                'narration' => trim("Shortage in transit against dispatch {$dispatch->number}. {$note}"),
                'lines' => array_map(fn ($l) => $l + ['godown_id' => $transit->id], $short),
            ], $user, $dispatch, 1) : null;
            AuditLog::record('updated', $dispatch, null, null, "Goods in transit {$dispatch->number}: "
                .implode(', ', array_filter([$receipt ? "received by {$receipt->number}" : null, $shortage ? "shortage written off by {$shortage->number}" : null])));

            return [$receipt, $shortage];
        });
    }

    /** Post a receipt or shortage journal and tie each of its lines to the dispatch line it settles. */
    protected function post(array $data, User $user, StockJournal $dispatch, int $perRow): StockJournal
    {
        $links = array_column($data['lines'], '_line');
        $data['lines'] = array_map(fn ($l) => array_diff_key($l, ['_line' => true]), $data['lines']);
        $journal = $this->journals->createInternal($data, $user, ['dispatch_id' => $dispatch->id]);
        foreach ($journal->lines()->orderBy('id')->get()->values() as $i => $line) {
            $line->update(['transit_line_id' => $links[intdiv($i, $perRow)]]);
        }

        return $journal->fresh('lines');
    }
}
