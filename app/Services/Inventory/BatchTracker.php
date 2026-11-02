<?php

namespace App\Services\Inventory;

use App\Models\Godown;
use App\Models\StockItem;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Batch and serial number balances, read from the stock movements.
 *
 * An item tracked by batch carries a batch number (and, if it tracks expiry,
 * an expiry date) on every line that moves it; an item tracked by serial
 * carries one serial number per unit. Balances are worked out from the
 * opening stock and the posted documents, the same way as quantities.
 *
 * Batch numbers and serials are compared without regard to case and stored
 * in upper case.
 */
class BatchTracker
{
    public const MAX_LENGTH = 50;

    public function __construct(protected StockLedger $ledger) {}

    /** Serial numbers from text: one per line or separated by commas. */
    public static function splitSerials(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($s) => mb_strtoupper(preg_replace('/\s+/', ' ', trim($s))),
            preg_split('/[\r\n,]+/', $text)
        ), fn ($s) => $s !== ''));
    }

    public static function normaliseBatch(?string $batch): ?string
    {
        $batch = mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) $batch)));

        return $batch === '' ? null : $batch;
    }

    /**
     * Movements of tracked items including opening stock, oldest first.
     *
     * @param  array{0:string,1:int}|null  $exclude  [doc_type, doc_id] left out, e.g. the document being edited
     */
    public function movements(?array $itemIds = null, ?string $to = null, ?array $exclude = null): Collection
    {
        $items = StockItem::query()->whereIn('tracking', ['batch', 'serial'])
            ->when($itemIds !== null, fn ($q) => $q->whereIn('id', $itemIds))->get();
        if ($items->isEmpty()) {
            return collect();
        }
        $defaultGodown = Godown::defaultId();

        $opening = $items->filter(fn ($i) => Decimal::toScaled($i->opening_quantity, 3) > 0)->map(fn (StockItem $i) => (object) [
            'stock_item_id' => $i->id, 'godown_id' => $i->opening_godown_id ?? $defaultGodown, 'date' => '0000-00-00', 'kind' => 'opening',
            'quantity' => Decimal::toScaled($i->opening_quantity, 3), 'value' => 0, 'doc_type' => 'opening', 'doc_id' => $i->id,
            'number' => null, 'party' => null, 'line_id' => 0,
            'batch_no' => $i->opening_batch_no, 'mfg_date' => $i->opening_mfg_date?->toDateString(),
            'expiry_date' => $i->opening_expiry_date?->toDateString(), 'serials' => self::splitSerials($i->opening_serials),
        ]);

        $rows = $this->ledger->movements($items->pluck('id')->all(), $to)
            ->reject(fn ($m) => $exclude && $m->doc_type === $exclude[0] && $m->doc_id === $exclude[1])
            ->map(function ($m) use ($defaultGodown) {
                $m->godown_id ??= $defaultGodown;

                return $m;
            });

        return $opening->values()->concat($rows)->values();
    }

    /**
     * Quantity of each batch in each godown.
     *
     * @return Collection<int, object{stock_item_id:int, godown_id:int, batch_no:string, quantity:int, mfg_date:?string, expiry_date:?string, first_in:string, last_date:string}>
     */
    public function batches(?string $asOf = null, ?array $itemIds = null, ?array $exclude = null): Collection
    {
        $out = [];
        foreach ($this->movements($itemIds, $asOf, $exclude) as $m) {
            if ($m->batch_no === null) {
                continue;
            }
            $key = $m->stock_item_id.'|'.$m->godown_id.'|'.$m->batch_no;
            $out[$key] ??= (object) ['stock_item_id' => $m->stock_item_id, 'godown_id' => $m->godown_id, 'batch_no' => $m->batch_no,
                'quantity' => 0, 'mfg_date' => null, 'expiry_date' => null, 'first_in' => $m->date, 'last_date' => $m->date];
            $b = $out[$key];
            $b->quantity += $m->quantity;
            $b->mfg_date ??= $m->mfg_date;
            $b->expiry_date ??= $m->expiry_date;
            $b->last_date = $m->date;
        }

        // A batch moved to another godown keeps its dates there.
        $dates = [];
        foreach ($out as $b) {
            $dates[$b->stock_item_id.'|'.$b->batch_no] ??= [$b->mfg_date, $b->expiry_date];
        }
        foreach ($out as $b) {
            [$mfg, $expiry] = $dates[$b->stock_item_id.'|'.$b->batch_no];
            $b->mfg_date ??= $mfg;
            $b->expiry_date ??= $expiry;
        }

        return collect(array_values($out));
    }

    /**
     * Where each serial is now: [item id => [serial => object{godown_id:int, in_stock:bool, date:string, kind:string, doc_type:string, doc_id:int, number:?string}]].
     */
    public function serialStates(?array $itemIds = null, ?array $exclude = null, ?string $asOf = null): array
    {
        $out = [];
        foreach ($this->movements($itemIds, $asOf, $exclude) as $m) {
            foreach ($m->serials as $serial) {
                $out[$m->stock_item_id][$serial] = (object) [
                    'godown_id' => $m->godown_id, 'in_stock' => $m->quantity > 0, 'date' => $m->date, 'kind' => $m->kind,
                    'doc_type' => $m->doc_type, 'doc_id' => $m->doc_id, 'number' => $m->number, 'party' => $m->party,
                ];
            }
        }

        return $out;
    }

    /** Every movement of one serial number, oldest first. */
    public function serialHistory(string $serial, ?array $itemIds = null): Collection
    {
        $serial = mb_strtoupper(trim($serial));

        return $this->movements($itemIds)->filter(fn ($m) => in_array($serial, $m->serials, true))->values();
    }

    /**
     * Validate and normalise the batch and serial fields of document lines.
     *
     * Each line: key (error key prefix, e.g. "items.0"), label, item (StockItem with unit),
     * godown_id, direction (in / out), quantity, batch_no, mfg_date, expiry_date, serials.
     *
     * @param  array{0:string,1:int}|null  $exclude  the document's own saved lines, left out of the balances
     * @param  bool  $blockExpired  refuse expired batches going out (sales)
     * @return array{0: array<int, array{batch_no:?string, mfg_date:?string, expiry_date:?string, serials:?string}>, 1: array<string,string>}
     *   normalised fields per line index and errors
     */
    public function check(array $lines, string $date, ?array $exclude = null, bool $blockExpired = false): array
    {
        $fields = [];
        $errors = [];
        $tracked = array_filter($lines, fn ($l) => in_array($l['item']->tracking, ['batch', 'serial'], true));
        $itemIds = array_values(array_unique(array_map(fn ($l) => $l['item']->id, $tracked)));

        $batches = $tracked ? $this->batches(null, $itemIds, $exclude)->keyBy(fn ($b) => $b->stock_item_id.'|'.$b->godown_id.'|'.$b->batch_no) : collect();
        $batchesOnDate = $tracked ? $this->batches($date, $itemIds, $exclude)->keyBy(fn ($b) => $b->stock_item_id.'|'.$b->godown_id.'|'.$b->batch_no) : collect();
        $serials = $tracked ? $this->serialStates($itemIds, $exclude) : [];
        $used = []; // quantity taken from each batch by earlier lines
        $seen = []; // serials already on this document, by direction

        foreach ($lines as $n => $line) {
            $item = $line['item'];
            $key = $line['key'];
            $label = $line['label'];
            $fields[$n] = ['batch_no' => null, 'mfg_date' => null, 'expiry_date' => null, 'serials' => null];
            $qty = Decimal::toScaled((string) $line['quantity'], 3);

            if ($item->tracking === 'batch') {
                $batch = self::normaliseBatch($line['batch_no'] ?? null);
                if ($batch === null) {
                    $errors["$key.batch_no"] = "{$label}: enter the batch number of {$item->name}.";

                    continue;
                }
                if (mb_strlen($batch) > self::MAX_LENGTH) {
                    $errors["$key.batch_no"] = "{$label}: a batch number can have at most ".self::MAX_LENGTH.' characters.';

                    continue;
                }
                $known = $batches->first(fn ($b) => $b->stock_item_id === $item->id && $b->batch_no === $batch);

                if ($line['direction'] === 'in') {
                    $mfg = $this->date($line['mfg_date'] ?? null);
                    $expiry = $this->date($line['expiry_date'] ?? null);
                    if ($mfg === false) {
                        $errors["$key.mfg_date"] = "{$label}: enter a valid manufacturing date.";
                    }
                    if ($expiry === false) {
                        $errors["$key.expiry_date"] = "{$label}: enter a valid expiry date.";
                    }
                    if ($mfg === false || $expiry === false) {
                        continue;
                    }
                    // A batch already on record keeps its dates.
                    $mfg ??= $known?->mfg_date;
                    $expiry ??= $known?->expiry_date;
                    if ($known && $known->expiry_date && $expiry !== $known->expiry_date) {
                        $errors["$key.expiry_date"] = "{$label}: batch {$batch} of {$item->name} already has expiry ".Carbon::parse($known->expiry_date)->format('d-m-Y').'.';

                        continue;
                    }
                    if ($item->track_expiry && ! $expiry) {
                        $errors["$key.expiry_date"] = "{$label}: enter the expiry date of batch {$batch}.";

                        continue;
                    }
                    if ($mfg && $expiry && $mfg > $expiry) {
                        $errors["$key.expiry_date"] = "{$label}: the expiry date is before the manufacturing date.";

                        continue;
                    }
                    $fields[$n] = ['batch_no' => $batch, 'mfg_date' => $mfg, 'expiry_date' => $expiry, 'serials' => null];

                    continue;
                }

                // Going out: the batch must hold enough in this godown on the date and afterwards.
                $bKey = $item->id.'|'.$line['godown_id'].'|'.$batch;
                $onDate = $batchesOnDate->get($bKey)?->quantity ?? 0;
                $now = $batches->get($bKey)?->quantity ?? 0;
                $available = min($onDate, $now) - ($used[$bKey] ?? 0);
                if (! $batches->has($bKey) && ! $batchesOnDate->has($bKey)) {
                    $errors["$key.batch_no"] = "{$label}: batch {$batch} of {$item->name} is not in this godown.";

                    continue;
                }
                if ($qty > $available) {
                    $errors["$key.batch_no"] = "{$label}: batch {$batch} of {$item->name} has only ".StockLedger::qty(max(0, $available), $item->unit).' available.';

                    continue;
                }
                $expiry = $batches->get($bKey)?->expiry_date ?? $known?->expiry_date;
                if ($blockExpired && $expiry && $expiry < $date) {
                    $errors["$key.batch_no"] = "{$label}: batch {$batch} of {$item->name} expired on ".Carbon::parse($expiry)->format('d-m-Y').'.';

                    continue;
                }
                $used[$bKey] = ($used[$bKey] ?? 0) + $qty;
                $fields[$n] = ['batch_no' => $batch, 'mfg_date' => $batches->get($bKey)?->mfg_date ?? $known?->mfg_date, 'expiry_date' => $expiry, 'serials' => null];

                continue;
            }

            if ($item->tracking === 'serial') {
                $list = self::splitSerials($line['serials'] ?? null);
                if ($qty % 1000 !== 0) {
                    $errors["$key.quantity"] = "{$label}: {$item->name} has serial numbers, so the quantity must be a whole number.";

                    continue;
                }
                if (count($list) !== intdiv($qty, 1000)) {
                    $errors["$key.serials"] = "{$label}: enter ".intdiv($qty, 1000).' serial number(s) for '.$item->name.' ('.count($list).' given).';

                    continue;
                }
                $problem = null;
                foreach ($list as $serial) {
                    $state = $serials[$item->id][$serial] ?? null;
                    $dir = $line['direction'];
                    if (mb_strlen($serial) > self::MAX_LENGTH) {
                        $problem = "serial {$serial} is longer than ".self::MAX_LENGTH.' characters';
                    } elseif (isset($seen[$dir][$item->id][$serial])) {
                        $problem = "serial {$serial} is entered twice";
                    } elseif ($dir === 'in' && $state?->in_stock) {
                        $problem = "serial {$serial} is already in stock";
                    } elseif ($dir === 'out' && (! $state || ! $state->in_stock)) {
                        $problem = "serial {$serial} is not in stock";
                    } elseif ($dir === 'out' && $state->godown_id !== (int) $line['godown_id']) {
                        $problem = "serial {$serial} is in another godown";
                    } elseif ($state && $state->date > $date) {
                        $problem = "serial {$serial} was last moved on ".Carbon::parse($state->date)->format('d-m-Y').', after this date';
                    }
                    if ($problem) {
                        break;
                    }
                    $seen[$dir][$item->id][$serial] = true;
                }
                if ($problem) {
                    $errors["$key.serials"] = "{$label}: {$problem}.";

                    continue;
                }
                $fields[$n] = ['batch_no' => null, 'mfg_date' => null, 'expiry_date' => null, 'serials' => implode("\n", $list)];
            }
        }

        return [$fields, $errors];
    }

    /**
     * Opening stock fields of an item, validated like an inward line.
     *
     * @return array{0: array, 1: array<string,string>} attributes and errors
     */
    public function checkOpening(StockItem $item, string $qty, ?string $batch, ?string $mfg, ?string $expiry, ?string $serials): array
    {
        $blank = ['opening_batch_no' => null, 'opening_mfg_date' => null, 'opening_expiry_date' => null, 'opening_serials' => null];
        $scaled = Decimal::toScaled($qty, 3);
        if ($scaled <= 0 || $item->tracking === 'none') {
            return [$blank, []];
        }
        $godown = $item->opening_godown_id ?? Godown::defaultId();
        $line = ['key' => 'opening', 'label' => 'Opening stock', 'item' => $item, 'godown_id' => $godown, 'direction' => 'in',
            'quantity' => $qty, 'batch_no' => $batch, 'mfg_date' => $mfg, 'expiry_date' => $expiry, 'serials' => $serials];
        [$fields, $errors] = $this->check([$line], '0000-00-00', $item->exists ? ['opening', $item->id] : null);
        $renamed = [];
        foreach ($errors as $key => $message) {
            $renamed[str_replace('opening.', 'opening_', $key)] = $message;
        }
        if ($renamed) {
            return [$blank, $renamed];
        }
        $f = $fields[0];

        return [['opening_batch_no' => $f['batch_no'], 'opening_mfg_date' => $f['mfg_date'], 'opening_expiry_date' => $f['expiry_date'], 'opening_serials' => $f['serials']], []];
    }

    /** A Y-m-d date, null when empty, false when invalid. */
    protected function date($value): string|null|false
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            return false;
        }

        return $value;
    }
}
