<?php

namespace App\Services\Inventory;

use App\Models\AuditLog;
use App\Models\Godown;
use App\Models\StockItem;
use App\Models\StockJournal;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records stock journals: godown transfers and stock adjustments
 * (shortage, excess, damage, consumption, production).
 *
 * A journal is posted when saved. To correct one, cancel it and enter it
 * again; a cancelled journal stays on record but no longer moves stock.
 */
class StockJournalService
{
    public function __construct(protected CurrentCompany $current, protected StockLedger $ledger) {}

    /**
     * @param array{date:string, purpose:string, narration?:?string, from_godown_id?:?int, to_godown_id?:?int, lines: array} $data
     *   Transfer lines: stock_item_id, quantity. Other lines: stock_item_id, godown_id, direction, quantity, rate.
     */
    public function create(array $data, User $user): StockJournal
    {
        $date = $this->validDate($data['date'] ?? null);
        $purpose = $data['purpose'] ?? '';
        if (! array_key_exists($purpose, StockJournal::PURPOSES)) {
            throw ValidationException::withMessages(['purpose' => 'Choose what the stock journal is for.']);
        }

        $lines = $purpose === 'transfer' ? $this->transferLines($data, $date) : $this->adjustmentLines($data, $date);

        return DB::transaction(function () use ($data, $user, $date, $purpose, $lines) {
            $journal = StockJournal::create([
                'number' => $this->nextNumber(),
                'date' => $date,
                'purpose' => $purpose,
                'status' => 'posted',
                'narration' => isset($data['narration']) ? mb_substr(trim((string) $data['narration']), 0, 2000) ?: null : null,
                'created_by' => $user->id,
            ]);
            foreach ($lines as $line) {
                $journal->lines()->create($line + ['company_id' => $journal->company_id]);
            }

            return $journal->load('lines.item.unit', 'lines.godown');
        });
    }

    public function cancel(StockJournal $journal, User $user, string $reason): StockJournal
    {
        if (! $journal->isPosted()) {
            throw ValidationException::withMessages(['journal' => 'This stock journal is already cancelled.']);
        }
        $year = $this->current->get()->financialYearFor($journal->date);
        if ($year && $year->isDateLocked($journal->date)) {
            throw ValidationException::withMessages(['journal' => 'The stock journal is in a locked financial year or period and cannot be cancelled.']);
        }

        $journal->update([
            'status' => 'cancelled',
            'cancellation_reason' => mb_substr($reason, 0, 255),
            'cancelled_by' => $user->id,
            'cancelled_at' => now(),
        ]);
        AuditLog::record('cancelled', $journal, null, null, "Stock journal {$journal->number} cancelled: {$reason}");

        return $journal;
    }

    /** Items whose quantity is below zero on the date in any godown they touch. */
    public function negativeItems(array $itemIds, string $date): array
    {
        $quantities = $this->ledger->godownQuantities($date, $itemIds);
        $godowns = Godown::query()->pluck('name', 'id');
        $items = StockItem::query()->with('unit')->whereIn('id', $itemIds)->get()->keyBy('id');
        $out = [];
        foreach ($quantities as $itemId => $byGodown) {
            foreach ($byGodown as $godownId => $qty) {
                if ($qty < 0 && isset($items[$itemId])) {
                    $out[] = $items[$itemId]->name.' in '.($godowns[$godownId] ?? 'godown').': '.StockLedger::qty($qty, $items[$itemId]->unit);
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------

    protected function validDate($value): string
    {
        try {
            $date = Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['date' => 'Enter a valid date.']);
        }
        if (empty($value)) {
            throw ValidationException::withMessages(['date' => 'Enter the date.']);
        }

        $company = $this->current->get();
        $year = $company->financialYearFor($date);
        if (! $year) {
            throw ValidationException::withMessages(['date' => 'The date is outside every financial year of this company.']);
        }
        if ($company->books_begin_date && $date->lt($company->books_begin_date)) {
            throw ValidationException::withMessages(['date' => 'The date is before the books beginning date ('.$company->books_begin_date->format('d M Y').').']);
        }
        if (! $company->setting('allow_future_dated_vouchers') && $date->gt(now()->endOfDay())) {
            throw ValidationException::withMessages(['date' => 'Future-dated entries are not allowed (change this in company preferences).']);
        }
        if ($year->isDateLocked($date)) {
            throw ValidationException::withMessages(['date' => 'This date falls in a locked financial year or period.']);
        }

        return $date->toDateString();
    }

    protected function transferLines(array $data, string $date): array
    {
        $godowns = Godown::query()->where('is_active', true)->pluck('id')->all();
        $from = (int) ($data['from_godown_id'] ?? 0);
        $to = (int) ($data['to_godown_id'] ?? 0);
        $errors = [];
        if (! in_array($from, $godowns, true)) {
            $errors['from_godown_id'] = 'Choose the godown the stock leaves.';
        }
        if (! in_array($to, $godowns, true)) {
            $errors['to_godown_id'] = 'Choose the godown the stock goes to.';
        } elseif ($from === $to) {
            $errors['to_godown_id'] = 'Choose two different godowns.';
        }

        $rows = $this->rows($data, $errors, false);
        // Batches and serials are checked where the stock leaves; they arrive unchanged.
        $fields = $this->tracking(array_map(fn ($r) => $r + ['godown_id' => $from, 'direction' => 'out'], $rows), $date);
        $lines = [];
        foreach ($rows as $n => $row) {
            $lines[] = ['stock_item_id' => $row['item']->id, 'godown_id' => $from, 'direction' => 'out', 'quantity' => $row['quantity'], 'rate' => '0', 'value' => '0'] + $fields[$n];
            $lines[] = ['stock_item_id' => $row['item']->id, 'godown_id' => $to, 'direction' => 'in', 'quantity' => $row['quantity'], 'rate' => '0', 'value' => '0'] + $fields[$n];
        }

        return $lines;
    }

    protected function adjustmentLines(array $data, string $date): array
    {
        $errors = [];
        $rows = $this->rows($data, $errors, true);
        $fields = $this->tracking($rows, $date);
        $positions = $this->ledger->position($date, StockItem::query()->whereIn('id', array_map(fn ($r) => $r['item']->id, $rows))->get());

        return array_map(function ($row, $n) use ($positions, $fields) {
            $qty = Decimal::toScaled($row['quantity'], 3);
            // Inward lines without a rate come in at the item's current average cost.
            $rate = $row['rate'] !== '' ? Money::toPaise($row['rate']) : ($positions[$row['item']->id]->rate ?? 0);
            $value = $row['direction'] === 'in' ? Decimal::mulDiv($qty, $rate, 1000) : 0;

            return [
                'stock_item_id' => $row['item']->id, 'godown_id' => $row['godown_id'], 'direction' => $row['direction'],
                'quantity' => $row['quantity'], 'rate' => Money::fromPaise($rate), 'value' => Money::fromPaise($value),
            ] + $fields[$n];
        }, $rows, array_keys($rows));
    }

    /** Validate the item rows; throws with all errors (including $errors gathered earlier). */
    protected function rows(array $data, array $errors, bool $withGodown): array
    {
        $input = array_values($data['lines'] ?? []);
        $items = StockItem::query()->with('unit')->whereIn('id', array_filter(array_map(fn ($l) => (int) ($l['stock_item_id'] ?? 0), $input)))->get()->keyBy('id');
        $godowns = Godown::query()->where('is_active', true)->pluck('id')->all();
        $rows = [];

        foreach ($input as $i => $line) {
            if (empty($line['stock_item_id']) && trim((string) ($line['quantity'] ?? '')) === '') {
                continue;
            }
            $label = 'Line '.(count($rows) + 1);
            $item = $items->get((int) ($line['stock_item_id'] ?? 0));
            if (! $item || ! $item->is_active) {
                $errors["lines.$i.stock_item_id"] = "{$label}: choose an active stock item.";

                continue;
            }
            $quantity = str_replace(',', '', trim((string) ($line['quantity'] ?? '')));
            $places = $item->unit->decimal_places;
            if (! preg_match('/^\d{1,7}(\.\d{1,3})?$/', $quantity) || (float) $quantity <= 0) {
                $errors["lines.$i.quantity"] = "{$label}: enter a quantity greater than zero.";
            } elseif (str_contains($quantity, '.') && strlen(rtrim(explode('.', $quantity)[1], '0')) > $places) {
                $errors["lines.$i.quantity"] = "{$label}: unit {$item->unit->symbol} allows {$places} decimal places.";
            }

            $row = ['item' => $item, 'quantity' => $quantity, 'key' => "lines.$i", 'label' => $label,
                'batch_no' => $line['batch_no'] ?? null, 'mfg_date' => $line['mfg_date'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null, 'serials' => $line['serials'] ?? null];
            if ($withGodown) {
                $godownId = (int) ($line['godown_id'] ?? 0);
                if (! in_array($godownId, $godowns, true)) {
                    $errors["lines.$i.godown_id"] = "{$label}: choose a godown.";
                }
                $direction = $line['direction'] ?? '';
                if (! in_array($direction, ['in', 'out'], true)) {
                    $errors["lines.$i.direction"] = "{$label}: choose whether stock comes in or goes out.";
                }
                $rate = str_replace(',', '', trim((string) ($line['rate'] ?? '')));
                if ($rate !== '' && ! preg_match('/^\d{1,11}(\.\d{1,2})?$/', $rate)) {
                    $errors["lines.$i.rate"] = "{$label}: enter a valid rate.";
                }
                $row += ['godown_id' => $godownId, 'direction' => $direction, 'rate' => $rate];
            }
            $rows[] = $row;
        }

        if ($rows === [] && ! $errors) {
            $errors['lines'] = 'Add at least one item.';
        }
        if (count($rows) > 200) {
            $errors['lines'] = 'A stock journal can have at most 200 lines.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    /** Batch / serial fields of each row (validated); throws on errors. */
    protected function tracking(array $rows, string $date): array
    {
        [$fields, $errors] = app(BatchTracker::class)->check(array_values($rows), $date);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $fields;
    }

    protected function nextNumber(): string
    {
        $last = StockJournal::query()->lockForUpdate()->pluck('number')
            ->map(fn ($n) => (int) preg_replace('/\D/', '', (string) $n))->max() ?? 0;

        return 'SJ/'.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }
}
