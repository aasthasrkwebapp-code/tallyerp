<?php

namespace App\Support;

/**
 * Rupees in words, Indian style: 122720.50 → "Rupees One Lakh Twenty-Two Thousand
 * Seven Hundred Twenty and Fifty Paise Only".
 */
class AmountInWords
{
    protected const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    protected const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function rupees(float|string $amount): string
    {
        $amount = round(abs((float) $amount), 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);

        $words = 'Rupees '.($rupees === 0 ? 'Zero' : self::number($rupees));
        if ($paise > 0) {
            $words .= ' and '.self::number($paise).' Paise';
        }

        return $words.' Only';
    }

    public static function number(int $n): string
    {
        // Crore, lakh, thousand, hundred, then the last two digits.
        $parts = [];
        foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand', 100 => 'Hundred'] as $size => $name) {
            if ($n >= $size) {
                $count = intdiv($n, $size);
                $parts[] = ($size === 10000000 && $count >= 100 ? self::number($count) : self::belowHundred($count)).' '.$name;
                $n %= $size;
            }
        }
        if ($n > 0) {
            $parts[] = self::belowHundred($n);
        }

        return implode(' ', $parts);
    }

    protected static function belowHundred(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        return self::TENS[intdiv($n, 10)].($n % 10 ? '-'.self::ONES[$n % 10] : '');
    }
}
