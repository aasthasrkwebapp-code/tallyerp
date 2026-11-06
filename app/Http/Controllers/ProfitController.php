<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\Books;
use App\Support\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Item-wise and party-wise profit: sale value (after discounts, before GST)
 * less cost of the goods at weighted average cost. Sales returns are deducted.
 */
class ProfitController extends Controller
{
    public function show(Request $request, Company $company, string $by)
    {
        $this->authorizeCompany($company);
        abort_unless(in_array($by, ['items', 'parties'], true), 404);

        [$fyStart, $fyEnd] = (new Books($company))->financialYear();
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = $request->filled('from') ? CarbonImmutable::parse($request->input('from')) : $fyStart;
        $to = $request->filled('to') ? CarbonImmutable::parse($request->input('to')) : $fyEnd;

        $lines = self::lines($company, $from, $to);

        $rows = $lines->groupBy($by === 'items' ? 'stock_item_id' : 'party_id')
            ->map(function (Collection $group) use ($by) {
                $first = $group->first();
                $value = round($group->sum('value'), 2);
                $cost = round($group->sum('cost'), 2);

                return (object) [
                    'name' => $by === 'items' ? ($first->item_name ?? 'Services / other') : $first->party_name,
                    'id' => $by === 'items' ? $first->stock_item_id : $first->party_id,
                    'quantity' => round($group->sum('quantity'), 3),
                    'value' => $value,
                    'cost' => $cost,
                    'profit' => round($value - $cost, 2),
                    'margin' => $value != 0.0 ? round(($value - $cost) * 100 / $value, 2) : null,
                ];
            })
            ->sortByDesc('profit')
            ->values();

        return view('reports.profit', compact('company', 'by', 'from', 'to', 'rows'));
    }

    /**
     * Sales and sales-return lines with their cost. Returns are negative.
     */
    public static function lines(Company $company, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $average = (new Stock($company))->summary($to, $to)->mapWithKeys(fn ($r) => [$r->item->id => $r->rate]);

        return DB::table('voucher_gst_lines')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_gst_lines.voucher_id')
            ->leftJoin('stock_items', 'stock_items.id', '=', 'voucher_gst_lines.stock_item_id')
            ->leftJoin('chart_of_accounts as parties', 'parties.id', '=', 'vouchers.party_account_id')
            ->where('vouchers.company_id', $company->id)
            ->whereIn('vouchers.type', ['sales', 'credit_note'])
            ->whereDate('vouchers.date', '>=', $from->toDateString())
            ->whereDate('vouchers.date', '<=', $to->toDateString())
            ->get([
                'vouchers.type', 'vouchers.party_account_id as party_id', 'parties.name as party_name',
                'voucher_gst_lines.stock_item_id', 'stock_items.name as item_name',
                'voucher_gst_lines.quantity', 'voucher_gst_lines.taxable',
            ])
            ->map(function ($l) use ($average) {
                $sign = $l->type === 'credit_note' ? -1 : 1;
                $qty = (float) $l->quantity * $sign;

                $l->quantity = $qty;
                $l->value = (float) $l->taxable * $sign;
                $l->cost = $l->stock_item_id ? $qty * (float) ($average[$l->stock_item_id] ?? 0) : 0.0;

                return $l;
            });
    }
}
