<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Voucher;
use App\Support\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cash flow statement (direct method) from posted vouchers.
 *
 * Cash means cash and bank ledgers. For every posted voucher that touches
 * one, each other line is the source or use of that cash: a line of Dr 100
 * on Rent means 100 paid out for rent. Contra vouchers between cash and bank
 * ledgers cancel out. Lines are classified by the counter ledger's group:
 * fixed assets and investments are investing, capital and loans are
 * financing, everything else is operating.
 */
class CashFlow
{
    public const SECTIONS = [
        'operating' => 'Cash flow from operating activities',
        'investing' => 'Cash flow from investing activities',
        'financing' => 'Cash flow from financing activities',
    ];

    protected const INVESTING = ['fixed_assets', 'investments'];

    protected const FINANCING = ['capital_account', 'loans_liability'];

    public function __construct(protected CurrentCompany $current, protected AccountBalances $balances) {}

    /**
     * @return array{sections: array, opening:int, closing:int, net:int, cashAccounts: Collection}
     *   Amounts in paise; positive = cash in.
     */
    public function statement(string $from, string $to): array
    {
        $cashIds = Account::query()->whereIn('account_type', ['cash', 'bank'])->pluck('id')->all();
        $balances = $this->balances->forPeriod($from, $to)->filter(fn ($b) => in_array($b->account->id, $cashIds, true));

        $flows = DB::table('voucher_entries as e')
            ->join('vouchers as v', 'v.id', '=', 'e.voucher_id')
            ->where('v.company_id', $this->current->id())
            ->where('v.status', Voucher::POSTED)
            ->whereBetween('v.date', [$from, $to])
            ->whereNotIn('e.account_id', $cashIds ?: [0])
            ->whereExists(fn ($q) => $q->from('voucher_entries as c')->whereColumn('c.voucher_id', 'v.id')->whereIn('c.account_id', $cashIds ?: [0]))
            ->groupBy('e.account_id')
            ->selectRaw('e.account_id, SUM(ROUND(e.credit * 100) - ROUND(e.debit * 100)) as flow, SUM(ROUND(e.credit * 100)) as inflow, SUM(ROUND(e.debit * 100)) as outflow')
            ->get();

        $accounts = Account::query()->with('group.parent')->whereIn('id', $flows->pluck('account_id'))->get()->keyBy('id');
        $sections = collect(self::SECTIONS)->map(fn ($label) => ['label' => $label, 'groups' => collect(), 'inflow' => 0, 'outflow' => 0, 'total' => 0])->all();

        foreach ($flows as $f) {
            $account = $accounts[$f->account_id];
            $key = $this->section($account->group);
            $flow = (int) round((float) $f->flow);
            $group = $account->group;

            $node = $sections[$key]['groups']->get($group->id) ?? (object) ['group' => $group, 'amount' => 0, 'ledgers' => collect()];
            $node->amount += $flow;
            $node->ledgers->push((object) ['account' => $account, 'amount' => $flow]);
            $sections[$key]['groups']->put($group->id, $node);

            $sections[$key]['inflow'] += (int) round((float) $f->inflow);
            $sections[$key]['outflow'] += (int) round((float) $f->outflow);
            $sections[$key]['total'] += $flow;
        }

        foreach ($sections as &$section) {
            $section['groups'] = $section['groups']->filter(fn ($n) => $n->amount !== 0 || $n->ledgers->contains(fn ($l) => $l->amount !== 0))
                ->sortBy(fn ($n) => $n->group->name)->values()
                ->each(fn ($n) => $n->ledgers = $n->ledgers->sortBy(fn ($l) => $l->account->name)->values());
        }
        unset($section);

        $opening = $balances->sum('opening');
        $closing = $balances->sum('closing');

        return [
            'sections' => $sections,
            'opening' => $opening,
            'closing' => $closing,
            'net' => array_sum(array_column($sections, 'total')),
            'cashAccounts' => $balances->values(),
        ];
    }

    protected function section(AccountGroup $group): string
    {
        foreach (self::INVESTING as $key) {
            if ($group->isBelow($key)) {
                return 'investing';
            }
        }
        foreach (self::FINANCING as $key) {
            if ($group->isBelow($key)) {
                return 'financing';
            }
        }

        return 'operating';
    }
}
