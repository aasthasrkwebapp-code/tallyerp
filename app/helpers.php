<?php

if (! function_exists('inr')) {
    /**
     * Format a number with Indian digit grouping: 1234567.5 → "12,34,567.50".
     */
    function inr(float|int|string|null $value, int $decimals = 2): string
    {
        $value = round((float) $value, $decimals);
        $negative = $value < 0;
        $fixed = number_format(abs($value), $decimals, '.', '');

        [$whole, $fraction] = array_pad(explode('.', $fixed, 2), 2, null);

        if (strlen($whole) > 3) {
            $lastThree = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            $whole = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).','.$lastThree;
        }

        return ($negative ? '-' : '').$whole.($fraction !== null ? '.'.$fraction : '');
    }
}
