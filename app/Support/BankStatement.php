<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads a bank statement CSV and matches its lines to uncleared bank entries.
 *
 * Columns are found by header name, so most bank exports work as-is:
 *   date (Txn Date / Value Date / Date), description (Narration / Particulars),
 *   withdrawal (Withdrawal / Debit / Dr), deposit (Deposit / Credit / Cr),
 *   reference (Chq / Ref No / Cheque).
 */
class BankStatement
{
    /** How far apart (in days) the book date and bank date may be. */
    public const MATCH_WINDOW_DAYS = 10;

    protected const HEADERS = [
        'date' => ['txn date', 'transaction date', 'value date', 'date'],
        'description' => ['narration', 'description', 'particulars', 'details', 'remarks'],
        'withdrawal' => ['withdrawal', 'withdrawal amt', 'debit', 'dr', 'debit amount'],
        'deposit' => ['deposit', 'deposit amt', 'credit', 'cr', 'credit amount'],
        'reference' => ['chq', 'chq no', 'chq./ref.no', 'chq/ref no', 'chq / ref no', 'cheque', 'cheque no', 'ref no', 'ref no./cheque no', 'reference', 'utr'],
    ];

    /**
     * @return Collection<int, array{line: int, date: CarbonImmutable, description: string, withdrawal: float, deposit: float, reference: ?string}>
     *
     * @throws \InvalidArgumentException when the file has no usable header
     */
    public static function parse(string $contents): Collection
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $rows = array_map('str_getcsv', preg_split('/\r\n|\n|\r/', trim($contents)));

        // The header is the first row that has a date column and an amount column.
        $map = null;
        foreach ($rows as $i => $row) {
            $map = self::columns($row);
            if (isset($map['date']) && (isset($map['withdrawal']) || isset($map['deposit']))) {
                $rows = array_slice($rows, $i + 1, null, true);
                break;
            }
            $map = null;
        }

        if (! $map) {
            throw new \InvalidArgumentException('Could not find the header row. The file needs a Date column and Withdrawal/Deposit (or Debit/Credit) columns.');
        }

        $lines = collect();
        foreach ($rows as $i => $row) {
            $date = self::date($row[$map['date']] ?? '');
            $withdrawal = self::amount($row[$map['withdrawal'] ?? -1] ?? '');
            $deposit = self::amount($row[$map['deposit'] ?? -1] ?? '');

            if (! $date || ($withdrawal == 0 && $deposit == 0)) {
                continue; // blank, opening balance or footer rows
            }

            $lines->push([
                'line' => $i + 1,
                'date' => $date,
                'description' => trim((string) ($row[$map['description'] ?? -1] ?? '')),
                'withdrawal' => $withdrawal,
                'deposit' => $deposit,
                'reference' => self::reference($row[$map['reference'] ?? -1] ?? null),
            ]);
        }

        return $lines;
    }

    /**
     * Pair statement lines with entries (VoucherEntry with ->voucher loaded).
     * Same direction and amount; a matching cheque/UTR number wins, otherwise the closest date.
     *
     * @return array{matched: array<int, array{line: array, entry: mixed}>, unmatched: array<int, array>}
     */
    public static function match(Collection $lines, Collection $entries): array
    {
        $available = $entries->keyBy('id');
        $matched = [];
        $unmatched = [];

        foreach ($lines as $line) {
            $isDeposit = $line['deposit'] > 0;
            $amount = $isDeposit ? $line['deposit'] : $line['withdrawal'];

            $candidates = $available->filter(function ($entry) use ($isDeposit, $amount, $line) {
                $bookAmount = (float) ($isDeposit ? $entry->debit : $entry->credit);

                return abs($bookAmount - $amount) < 0.005
                    && abs($entry->voucher->date->diffInDays($line['date'], false)) <= self::MATCH_WINDOW_DAYS;
            });

            $pick = $candidates->first(fn ($e) => $line['reference'] && self::reference($e->instrument_no) === $line['reference'])
                ?? $candidates->sortBy(fn ($e) => abs($e->voucher->date->diffInDays($line['date'], false)))->first();

            if ($pick) {
                $matched[] = ['line' => $line, 'entry' => $pick];
                $available->forget($pick->id);
            } else {
                $unmatched[] = $line;
            }
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    protected static function columns(array $row): array
    {
        $map = [];
        foreach ($row as $index => $cell) {
            $name = strtolower(trim(preg_replace('/\s+/', ' ', (string) $cell), " \t.:()"));
            foreach (self::HEADERS as $key => $names) {
                if (! isset($map[$key]) && in_array($name, $names, true)) {
                    $map[$key] = $index;
                }
            }
        }

        return $map;
    }

    protected static function date(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        foreach (['d/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y', 'Y-m-d', 'd M Y', 'd-M-Y', 'd M y', 'd-M-y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date && $date->format($format) === $value) {
                return CarbonImmutable::instance($date);
            }
        }

        return null;
    }

    protected static function amount(string $value): float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', $value);

        return $clean === '' || $clean === '-' ? 0.0 : abs(round((float) $clean, 2));
    }

    protected static function reference(?string $value): ?string
    {
        $value = ltrim(preg_replace('/\s+/', '', (string) $value), '0');

        return $value === '' ? null : strtoupper($value);
    }
}
