<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Fixed-point helpers for quantities and percentages, without floating point.
 */
final class Decimal
{
    /** "12.5" with scale 3 => 12500 (rounded half away from zero). */
    public static function toScaled(string|int|float|null $value, int $scale): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_float($value)) {
            $value = number_format($value, $scale + 3, '.', '');
        }

        $value = str_replace([',', ' '], '', trim((string) $value));
        if (! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $value, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException("Invalid number: {$value}");
        }

        $fraction = str_pad($m[3] ?? '', $scale + 1, '0');
        $scaled = (int) ($m[2] === '' ? '0' : $m[2]) * (10 ** $scale) + (int) substr($fraction, 0, $scale);
        if ((int) $fraction[$scale] >= 5) {
            $scaled++;
        }

        return $m[1] === '-' ? -$scaled : $scaled;
    }

    /** 12500 with scale 3 => "12.500". */
    public static function fromScaled(int $scaled, int $scale): string
    {
        $sign = $scaled < 0 ? '-' : '';
        $scaled = abs($scaled);
        $factor = 10 ** $scale;

        return $sign.intdiv($scaled, $factor).($scale > 0 ? '.'.str_pad((string) ($scaled % $factor), $scale, '0', STR_PAD_LEFT) : '');
    }

    /** Integer division of a non-negative numerator, rounded half up. */
    public static function roundDiv(int $numerator, int $denominator): int
    {
        if ($numerator < 0) {
            return -intdiv(-$numerator + intdiv($denominator, 2), $denominator);
        }

        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    /** Trim trailing zeros for display: "18.00" => "18", "2.500" => "2.5". */
    public static function trim(string|int|float|null $value): string
    {
        $value = (string) $value;

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
