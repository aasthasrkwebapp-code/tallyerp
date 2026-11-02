<?php

namespace App\Support;

/**
 * Amounts in words using the Indian numbering system (lakh, crore).
 */
final class NumberToWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    /** 123456789 paise => "Indian Rupees Twelve Lakh Thirty Four Thousand Five Hundred Sixty Seven and Eighty Nine Paise Only". */
    public static function rupees(int $paise): string
    {
        $negative = $paise < 0;
        $paise = abs($paise);
        $rupees = intdiv($paise, 100);
        $rest = $paise % 100;

        $words = 'Indian Rupees '.($rupees === 0 ? 'Zero' : self::words($rupees));
        if ($rest > 0) {
            $words .= ' and '.self::words($rest).' Paise';
        }

        return ($negative ? 'Minus ' : '').$words.' Only';
    }

    public static function words(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$unit, $name]) {
            if ($number >= $unit) {
                $count = intdiv($number, $unit);
                // Crores can exceed 99 (e.g. 1,000 crore), so spell them recursively.
                $parts[] = ($count > 99 ? self::words($count) : self::belowHundred($count)).' '.$name;
                $number %= $unit;
            }
        }
        if ($number > 0) {
            $parts[] = self::belowHundred($number);
        }

        return implode(' ', $parts);
    }

    private static function belowHundred(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        return trim(self::TENS[intdiv($n, 10)].' '.self::ONES[$n % 10]);
    }
}
