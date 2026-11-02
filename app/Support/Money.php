<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact money arithmetic in integer paise.
 *
 * Amounts arrive as decimal strings (from forms and DECIMAL columns) and are
 * converted to integers without passing through floating point.
 */
final class Money
{
    /** Convert "1234.5", "-0.07", 12, "1,234.50" to paise (rounded half away from zero). */
    public static function toPaise(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        if (is_int($amount)) {
            return $amount * 100;
        }

        if (is_float($amount)) {
            $amount = number_format($amount, 6, '.', '');
        }

        $amount = str_replace([',', ' ', '₹'], '', trim($amount));

        if (! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $amount, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException("Invalid amount: {$amount}");
        }

        $negative = $m[1] === '-';
        $whole = (int) ($m[2] === '' ? '0' : $m[2]);
        $fraction = str_pad($m[3] ?? '', 3, '0');
        $paise = $whole * 100 + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $paise++;
        }

        return $negative ? -$paise : $paise;
    }

    /** Paise to a DECIMAL string, e.g. 123450 => "1234.50". */
    public static function fromPaise(int $paise): string
    {
        $sign = $paise < 0 ? '-' : '';
        $paise = abs($paise);

        return $sign.intdiv($paise, 100).'.'.str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Normalise any amount to a 2-decimal string. */
    public static function normalize(string|int|float|null $amount): string
    {
        return self::fromPaise(self::toPaise($amount));
    }

    /** Indian digit grouping: 12345678.9 => "1,23,45,678.90". */
    public static function format(string|int|float|null $amount, bool $symbol = false, bool $paiseInput = false): string
    {
        $paise = $paiseInput ? (int) $amount : self::toPaise($amount);
        $negative = $paise < 0;
        [$whole, $fraction] = explode('.', self::fromPaise(abs($paise)));

        if (strlen($whole) > 3) {
            $last3 = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $whole = $rest.','.$last3;
        }

        return ($negative ? '-' : '').($symbol ? '₹' : '').$whole.'.'.$fraction;
    }

    /** Format a signed balance as "1,200.00 Dr" / "1,200.00 Cr" (positive = debit). */
    public static function drCr(int $paise): string
    {
        if ($paise === 0) {
            return '0.00';
        }

        return self::format(abs($paise), paiseInput: true).($paise > 0 ? ' Dr' : ' Cr');
    }

    /** Split paise across weights so the parts add back to the total exactly. */
    public static function allocate(int $paise, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum == 0) {
            return array_map(fn () => 0, $weights);
        }

        $parts = [];
        $allocated = 0;
        foreach ($weights as $key => $weight) {
            $parts[$key] = intdiv($paise * $weight, $sum);
            $allocated += $parts[$key];
        }

        $remainder = $paise - $allocated;
        foreach (array_keys($parts) as $key) {
            if ($remainder === 0) {
                break;
            }
            $parts[$key] += $remainder > 0 ? 1 : -1;
            $remainder += $remainder > 0 ? -1 : 1;
        }

        return $parts;
    }
}
