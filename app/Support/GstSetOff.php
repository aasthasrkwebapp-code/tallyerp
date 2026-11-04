<?php

namespace App\Support;

/**
 * Sets input tax credit off against output tax in the order the GST law allows
 * (section 49 / rule 88A):
 *   1. IGST credit → IGST, then CGST, then SGST (used up before any CGST/SGST credit)
 *   2. CGST credit → CGST, then IGST (never SGST)
 *   3. SGST credit → SGST, then IGST (never CGST)
 */
class GstSetOff
{
    public const HEADS = ['igst', 'cgst', 'sgst'];

    protected const ORDER = [
        'igst' => ['igst', 'cgst', 'sgst'],
        'cgst' => ['cgst', 'igst'],
        'sgst' => ['sgst', 'igst'],
    ];

    /**
     * @param  array{igst: float, cgst: float, sgst: float}  $liability  output tax
     * @param  array{igst: float, cgst: float, sgst: float}  $credit  input tax credit
     * @return array{used: array<string, array<string, float>>, cash: array<string, float>, carry_forward: array<string, float>}
     *         used[creditHead][liabilityHead] = amount set off
     */
    public static function compute(array $liability, array $credit): array
    {
        $due = array_map(fn ($v) => max(0.0, round((float) $v, 2)), array_intersect_key($liability, array_flip(self::HEADS)));
        $left = array_map(fn ($v) => max(0.0, round((float) $v, 2)), array_intersect_key($credit, array_flip(self::HEADS)));
        $used = array_fill_keys(self::HEADS, array_fill_keys(self::HEADS, 0.0));

        foreach (self::ORDER as $from => $targets) {
            foreach ($targets as $to) {
                $amount = round(min($left[$from], $due[$to]), 2);
                $used[$from][$to] = $amount;
                $left[$from] = round($left[$from] - $amount, 2);
                $due[$to] = round($due[$to] - $amount, 2);
            }
        }

        return ['used' => $used, 'cash' => $due, 'carry_forward' => $left];
    }
}
