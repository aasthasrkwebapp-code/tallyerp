<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Support\Books;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Receivables (Sundry Debtors) and payables (Sundry Creditors) with ageing.
 * Payments settle the oldest bills first.
 */
class OutstandingController extends Controller
{
    public const BUCKETS = ['0-30' => 30, '31-60' => 60, '61-90' => 90, '90+' => PHP_INT_MAX];

    public function show(Request $request, Company $company, string $kind)
    {
        $this->authorizeCompany($company);
        abort_unless(in_array($kind, ['receivables', 'payables'], true), 404);

        $request->validate(['to' => ['nullable', 'date']]);

        $books = new Books($company);
        [$fyStart, $fyEnd] = $books->financialYear();
        // Ageing is measured to today by default (or the year end, for a past year).
        $to = $request->filled('to')
            ? CarbonImmutable::parse($request->input('to'))
            : (CarbonImmutable::today()->between($fyStart, $fyEnd) ? CarbonImmutable::today() : $fyEnd);

        $groupCode = $kind === 'receivables' ? 'SUNDRY_DEBTORS' : 'SUNDRY_CREDITORS';
        // Receivables are debit balances; payables credit balances.
        $sign = $kind === 'receivables' ? 1 : -1;

        // Receivables also show what each customer earned you this year.
        $sold = $kind === 'receivables' ? \App\Support\Profit::lines($company, $fyStart, $to)->groupBy('party_id') : collect();

        $parties = $this->partiesUnder($company, $books, $groupCode)
            ->map(function (Account $party) use ($sign, $fyStart, $to, $sold) {
                $row = $this->age($party, $sign, $fyStart, $to);
                ['value' => $row->sold_value, 'profit' => $row->profit] = \App\Support\Profit::totals($sold->get($party->id, collect()));

                return $row;
            })
            ->filter(fn (object $row) => $row->total != 0 || $row->sold_value != 0)
            ->sortByDesc('total')
            ->values();

        $totals = collect(array_keys(self::BUCKETS))
            ->mapWithKeys(fn ($b) => [$b => round($parties->sum(fn ($p) => $p->buckets[$b]), 2)])
            ->all();

        return view('reports.outstanding', [
            'company' => $company,
            'kind' => $kind,
            'to' => $to,
            'parties' => $parties,
            'totals' => $totals,
            'grandTotal' => round($parties->sum('total'), 2),
        ]);
    }

    protected function age(Account $party, int $sign, CarbonImmutable $fyStart, CarbonImmutable $to): object
    {
        $entries = $party->entries()
            ->select('voucher_entries.*', 'vouchers.date as voucher_date')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            ->whereDate('vouchers.date', '<=', $to->toDateString())
            ->orderBy('vouchers.date')
            ->orderBy('vouchers.id')
            ->get();

        // Bills raise the balance in the party's normal direction; anything else settles them.
        $bills = collect();
        $settled = 0.0;

        $opening = $party->signedOpeningBalance() * $sign;
        if ($opening > 0) {
            $bills->push(['date' => $fyStart, 'amount' => $opening]);
        } else {
            $settled += -$opening;
        }

        foreach ($entries as $entry) {
            $amount = ((float) $entry->debit - (float) $entry->credit) * $sign;
            if ($amount > 0) {
                $bills->push(['date' => CarbonImmutable::parse($entry->voucher_date), 'amount' => $amount]);
            } else {
                $settled += -$amount;
            }
        }

        $buckets = array_fill_keys(array_keys(self::BUCKETS), 0.0);
        $overdue = 0.0;
        $remaining = $bills->map(function (array $bill) use (&$settled) {
            $used = min($bill['amount'], $settled);
            $settled -= $used;

            return ['date' => $bill['date'], 'amount' => $bill['amount'] - $used];
        });

        foreach ($remaining as $bill) {
            if ($bill['amount'] <= 0) {
                continue;
            }
            $days = (int) $bill['date']->diffInDays($to);
            if ($party->credit_days !== null && $days > $party->credit_days) {
                $overdue += $bill['amount'];
            }
            foreach (self::BUCKETS as $name => $limit) {
                if ($days <= $limit) {
                    $buckets[$name] += $bill['amount'];
                    break;
                }
            }
        }

        $buckets = array_map(fn ($v) => round($v, 2), $buckets);
        $advance = round($settled, 2); // paid more than billed

        return (object) [
            'party' => $party,
            'buckets' => $buckets,
            'advance' => $advance,
            'total' => $total = round(array_sum($buckets) - $advance, 2),
            // Bills older than the party's credit days, less any advance.
            'overdue' => max(0.0, round($overdue - $advance, 2)),
            'over_limit' => $party->credit_limit !== null && $total > (float) $party->credit_limit,
        ];
    }

    /**
     * @return Collection<int, Account>
     */
    protected function partiesUnder(Company $company, Books $books, string $groupCode): Collection
    {
        $rootId = $company->accountGroups()->where('code', $groupCode)->value('id');

        return $company->accounts()->orderBy('name')->get()->filter(function (Account $a) use ($books, $rootId) {
            for ($groupId = $a->account_group_id; $groupId; $groupId = $books->groupParent($groupId)) {
                if ((int) $groupId === (int) $rootId) {
                    return true;
                }
            }

            return false;
        });
    }
}
