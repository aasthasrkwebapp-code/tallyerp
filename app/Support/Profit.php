<?php

namespace App\Support;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales profit: sale value (after discounts, before GST) less cost of goods at
 * weighted average cost. Sales returns (credit notes) count negative.
 * Shown item-wise on the Stock Summary and party-wise on Receivables.
 */
class Profit
{
    public static function lines(Company $company, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $average = (new Stock($company))->summary($to, $to)->mapWithKeys(fn ($r) => [$r->item->id => $r->rate]);

        return DB::table('voucher_gst_lines')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_gst_lines.voucher_id')
            ->where('vouchers.company_id', $company->id)
            ->whereIn('vouchers.type', ['sales', 'credit_note'])
            ->whereDate('vouchers.date', '>=', $from->toDateString())
            ->whereDate('vouchers.date', '<=', $to->toDateString())
            ->get(['vouchers.type', 'vouchers.party_account_id as party_id', 'voucher_gst_lines.stock_item_id', 'voucher_gst_lines.quantity', 'voucher_gst_lines.taxable'])
            ->map(function ($l) use ($average) {
                $sign = $l->type === 'credit_note' ? -1 : 1;
                $qty = (float) $l->quantity * $sign;

                $l->quantity = $qty;
                $l->value = (float) $l->taxable * $sign;
                $l->cost = $l->stock_item_id ? $qty * (float) ($average[$l->stock_item_id] ?? 0) : 0.0;

                return $l;
            });
    }

    /**
     * @return array{value: float, cost: float, profit: float}
     */
    public static function totals(Collection $lines): array
    {
        $value = round($lines->sum('value'), 2);
        $cost = round($lines->sum('cost'), 2);

        return ['value' => $value, 'cost' => $cost, 'profit' => round($value - $cost, 2)];
    }
}
