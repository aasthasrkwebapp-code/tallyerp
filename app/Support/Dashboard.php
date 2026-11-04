<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Headline figures for a company, as on a date within its current financial year.
 */
class Dashboard
{
    public static function figures(Company $company, ?CarbonImmutable $on = null): object
    {
        $books = new Books($company);
        [$fyStart, $fyEnd] = $books->financialYear();
        $on = $on ? min($on, $fyEnd) : min(CarbonImmutable::today(), $fyEnd);
        if ($on->lt($fyStart)) {
            $on = $fyEnd;
        }

        $accounts = $books->closingBalances($on);
        $sum = fn (array $codes) => round($accounts->filter(fn (Account $a) => $books->isUnder($a, $codes))->sum('balance'), 2);

        $stock = new Stock($company);
        $closingStock = $stock->closingValue($on);
        $openingStock = $stock->closingValue(Books::yearStartFor($on)->subDay());
        $nominal = $accounts->filter(fn (Account $a) => $books->isNominal($a))->sum('balance');

        $gstOutput = -$accounts->filter(fn (Account $a) => str_starts_with((string) $a->code, 'GST_OUTPUT_'))->sum('balance');
        $gstInput = $accounts->filter(fn (Account $a) => str_starts_with((string) $a->code, 'GST_INPUT_'))->sum('balance');

        return (object) [
            'on' => $on,
            'cash_bank' => $sum(['CASH_IN_HAND', 'BANK_ACCOUNTS', 'BANK_OD']),
            'receivables' => $sum(['SUNDRY_DEBTORS']),
            'payables' => -$sum(['SUNDRY_CREDITORS']),
            'sales' => -$sum(['SALES_ACCOUNTS']),
            'net_profit' => round(-$nominal + $closingStock - $openingStock, 2),
            'stock_value' => $closingStock,
            'gst_payable' => round($gstOutput - $gstInput, 2),
            'monthly_sales' => self::monthlySales($company, $books, $fyStart, $fyEnd),
            'recent' => $company->vouchers()->with('entries.account')->latest('date')->latest('id')->limit(8)->get(),
        ];
    }

    /**
     * Sales (credit to sales ledgers) per month of the financial year: ['Apr' => 1200.0, ...].
     *
     * @return array<string, float>
     */
    protected static function monthlySales(Company $company, Books $books, CarbonImmutable $fyStart, CarbonImmutable $fyEnd): array
    {
        $salesIds = $company->accounts()->get()
            ->filter(fn (Account $a) => $books->isUnder($a, ['SALES_ACCOUNTS']))
            ->pluck('id');

        $rows = DB::table('voucher_entries')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            ->where('vouchers.company_id', $company->id)
            ->whereIn('voucher_entries.account_id', $salesIds)
            ->whereBetween('vouchers.date', [$fyStart->toDateString(), $fyEnd->toDateString()])
            ->get(['vouchers.date', 'voucher_entries.debit', 'voucher_entries.credit']);

        $months = [];
        for ($m = $fyStart; $m->lte($fyEnd); $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = 0.0;
        }

        foreach ($rows as $row) {
            $key = substr((string) $row->date, 0, 7);
            if (isset($months[$key])) {
                $months[$key] += (float) $row->credit - (float) $row->debit;
            }
        }

        return collect($months)
            ->mapWithKeys(fn ($v, $k) => [CarbonImmutable::createFromFormat('Y-m-d', $k.'-01')->format('M') => round($v, 2)])
            ->all();
    }
}
