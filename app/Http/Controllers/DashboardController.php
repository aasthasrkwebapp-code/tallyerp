<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Services\Reports\AccountBalances;
use App\Services\Reports\FinancialStatements;
use App\Support\CurrentCompany;

class DashboardController extends Controller
{
    public function __invoke(CurrentCompany $current, AccountBalances $balances, FinancialStatements $statements)
    {
        $company = $current->get();
        $figures = null;

        if (auth()->user()->can('reports.view')) {
            [$from, $to] = $balances->defaultPeriod();
            $rows = $balances->forPeriod($from, $to);
            $pl = $statements->profitAndLoss($from, $to);

            $sumType = fn (string $type) => $rows->filter(fn ($r) => $r->account->account_type === $type)->sum('closing');
            $sumGroup = fn (string $key) => $rows->filter(fn ($r) => $r->account->group->isBelow($key))->sum('closing');

            $figures = [
                'from' => $from,
                'to' => $to,
                'income' => $pl['totals']['trading_income'] + $pl['totals']['other_income'],
                'expenses' => $pl['totals']['trading_expense'] + $pl['totals']['other_expense'],
                'profit' => $pl['net_profit'],
                'cash' => $sumType('cash'),
                'bank' => $sumType('bank'),
                'receivables' => $sumGroup('sundry_debtors'),
                'payables' => -$sumGroup('sundry_creditors'),
                'pending' => Voucher::where('status', Voucher::PENDING)->count(),
                'drafts' => Voucher::where('status', Voucher::DRAFT)->count(),
                'recent' => Voucher::with('type')->where('status', Voucher::POSTED)->latest('date')->latest('id')->limit(8)->get(),
                'monthly' => $this->monthly($from, $to),
            ];
        }

        $documents = auth()->user()->can('transport.view') ? app(\App\Services\Transport\DocumentExpiry::class)->counts() : null;

        return view('dashboard', [
            'documents' => $documents,
            'company' => $company,
            'year' => $current->financialYear(),
            'figures' => $figures,
        ]);
    }

    /** Income and expense per month of the period, from posted entries. */
    protected function monthly(string $from, string $to): array
    {
        $rows = \DB::table('voucher_entries as e')
            ->join('vouchers as v', 'v.id', '=', 'e.voucher_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'e.account_id')
            ->join('account_groups as g', 'g.id', '=', 'a.account_group_id')
            ->where('v.company_id', app(CurrentCompany::class)->id())
            ->where('v.status', Voucher::POSTED)
            ->whereBetween('v.date', [$from, $to])
            ->whereIn('g.nature', ['income', 'expense'])
            ->get(['v.date', 'g.nature', 'e.debit', 'e.credit']);

        $months = [];
        foreach ($rows as $r) {
            $key = substr($r->date, 0, 7);
            $months[$key] ??= ['income' => 0, 'expense' => 0];
            $net = \App\Support\Money::toPaise($r->debit) - \App\Support\Money::toPaise($r->credit);
            if ($r->nature === 'income') {
                $months[$key]['income'] -= $net;
            } else {
                $months[$key]['expense'] += $net;
            }
        }
        ksort($months);

        return $months;
    }
}
