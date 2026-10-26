<?php

namespace App\Services\Billing;

use App\Support\Decimal;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Computes invoice lines and totals in integer paise.
 *
 * Per line: gross = quantity × rate, discount = gross × discount %,
 * taxable = gross − discount, then GST on the taxable value: half CGST and
 * half SGST for an intra-state supply, IGST for an inter-state supply, and no
 * tax when the tax mode is "none". Every amount is rounded to the paisa at
 * line level and the totals are the sum of the lines, so the printed invoice
 * and the posted voucher always agree. The grand total is optionally rounded
 * to the nearest rupee and the difference is shown as round off.
 */
class InvoiceCalculator
{
    /** Largest gross line value accepted (₹1 lakh crore), keeps the integer maths exact. */
    public const MAX_LINE_PAISE = 1_000_000_000_000_00;

    /**
     * @param  array<int, array{quantity: string, rate: string, discount_percent?: string, gst_rate?: string}>  $lines
     * @return array{lines: array<int, array<string, int>>, totals: array<string, int>}
     */
    public function calculate(array $lines, string $taxMode, bool $roundOff = true): array
    {
        $out = [];
        $totals = ['gross' => 0, 'discount' => 0, 'taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];

        foreach ($lines as $key => $line) {
            $qty = Decimal::toScaled($line['quantity'] ?? '0', 3);
            $rate = Money::toPaise($line['rate'] ?? '0');
            $discountBp = Decimal::toScaled($line['discount_percent'] ?? '0', 2);
            $gstBp = $taxMode === 'none' ? 0 : Decimal::toScaled($line['gst_rate'] ?? '0', 2);

            if ($qty < 0 || $rate < 0 || $discountBp < 0 || $discountBp > 10000 || $gstBp < 0 || $gstBp > 10000) {
                throw new InvalidArgumentException('Quantities, rates and percentages must be positive.');
            }
            if ($qty > 0 && $rate > intdiv(self::MAX_LINE_PAISE, max(1, intdiv($qty, 1000) + 1))) {
                throw new InvalidArgumentException('The line amount is too large.');
            }

            $gross = $rate * intdiv($qty, 1000) + Decimal::roundDiv($rate * ($qty % 1000), 1000);
            $discount = Decimal::roundDiv($gross * $discountBp, 10000);
            $taxable = $gross - $discount;

            $cgst = $sgst = $igst = 0;
            if ($taxMode === 'intra') {
                $cgst = $sgst = Decimal::roundDiv($taxable * $gstBp, 20000);
            } elseif ($taxMode === 'inter') {
                $igst = Decimal::roundDiv($taxable * $gstBp, 10000);
            }

            $out[$key] = [
                'gross' => $gross,
                'discount' => $discount,
                'taxable' => $taxable,
                'cgst' => $cgst,
                'sgst' => $sgst,
                'igst' => $igst,
                'total' => $taxable + $cgst + $sgst + $igst,
            ];

            foreach ($totals as $k => $v) {
                $totals[$k] += $out[$key][$k];
            }
        }

        $beforeRounding = $totals['taxable'] + $totals['cgst'] + $totals['sgst'] + $totals['igst'];
        $grand = $roundOff ? Decimal::roundDiv($beforeRounding, 100) * 100 : $beforeRounding;

        $totals['tax'] = $totals['cgst'] + $totals['sgst'] + $totals['igst'];
        $totals['round_off'] = $grand - $beforeRounding;
        $totals['grand'] = $grand;

        return ['lines' => $out, 'totals' => $totals];
    }
}
