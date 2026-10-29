<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CostCentre;
use App\Models\User;
use App\Models\Voucher;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves budgets and compares them with the books.
 *
 * Amounts are on the natural side of the line's group: debit for assets and
 * expenses, credit for liabilities and income. A "nett" line is compared with
 * the transactions from the budget's start to the report date, against the
 * budget for those days: pro rata by days, or, for a phased line, the months
 * before the report date's month plus that month's amount pro rata. A "closing" line is compared with the
 * balance on the report date. Group and cost centre lines include their
 * sub-groups, ledgers and sub-cost-centres.
 */
class BudgetVariance
{
    public function __construct(protected AccountBalances $balances, protected CurrentCompany $current) {}

    /**
     * @param  array{name: string, from_date: string, to_date: string, description?: ?string,
     *               lines: array<int, array{target?: ?string, cost_centre_id?: ?int, basis?: ?string, amount?: ?string}>}  $data
     */
    public function save(?Budget $budget, array $data, User $user): Budget
    {
        if ($data['to_date'] < $data['from_date']) {
            throw ValidationException::withMessages(['to_date' => 'The budget cannot end before it starts.']);
        }
        if (Carbon::parse($data['from_date'])->diffInDays(Carbon::parse($data['to_date'])) > 366) {
            throw ValidationException::withMessages(['to_date' => 'A budget can cover at most one year. Make one budget for each year.']);
        }

        $lines = [];
        $seen = [];
        $errors = [];
        foreach (array_values($data['lines'] ?? []) as $i => $line) {
            $target = trim((string) ($line['target'] ?? ''));
            $amount = trim((string) ($line['amount'] ?? ''));
            if ($target === '' && $amount === '') {
                continue;
            }
            if (! preg_match('/^([gl]):(\d+)$/', $target, $m)) {
                $errors["lines.$i.target"] = 'Line '.($i + 1).': choose a group or ledger.';

                continue;
            }
            $groupId = $m[1] === 'g' ? (int) $m[2] : null;
            $accountId = $m[1] === 'l' ? (int) $m[2] : null;
            if (($groupId && ! AccountGroup::query()->whereKey($groupId)->exists()) || ($accountId && ! Account::query()->whereKey($accountId)->exists())) {
                $errors["lines.$i.target"] = 'Line '.($i + 1).': choose a group or ledger of this company.';

                continue;
            }
            $centre = ($line['cost_centre_id'] ?? null) ? (int) $line['cost_centre_id'] : null;
            if ($centre && ! CostCentre::query()->whereKey($centre)->exists()) {
                $errors["lines.$i.cost_centre_id"] = 'Line '.($i + 1).': choose a cost centre of this company.';

                continue;
            }
            $basis = array_key_exists($line['basis'] ?? '', BudgetLine::BASES) ? $line['basis'] : 'nett';
            if ($basis === 'closing' && $centre) {
                $errors["lines.$i.basis"] = 'Line '.($i + 1).': a cost centre budget is on transactions only, not on a closing balance.';

                continue;
            }
            try {
                $paise = Money::toPaise($amount === '' ? '0' : $amount);
            } catch (\InvalidArgumentException) {
                $errors["lines.$i.amount"] = 'Line '.($i + 1).': enter a valid amount.';

                continue;
            }
            $key = $target.'|'.$centre;
            if (isset($seen[$key])) {
                $errors["lines.$i.target"] = 'Line '.($i + 1).': the same group or ledger is already budgeted'.($centre ? ' for this cost centre' : '').'.';

                continue;
            }
            $seen[$key] = true;
            $phasing = is_array($line['phasing'] ?? null) && $basis === 'nett' && array_sum(array_map('intval', $line['phasing'])) === $paise ? array_map('intval', $line['phasing']) : null;
            $lines[] = ['account_group_id' => $groupId, 'account_id' => $accountId, 'cost_centre_id' => $centre, 'basis' => $basis, 'amount' => Money::fromPaise($paise), 'phasing' => $phasing];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one group or ledger with an amount.']);
        }

        return DB::transaction(function () use ($budget, $data, $lines, $user) {
            $budget ??= new Budget(['created_by' => $user->id]);
            $budget->fill([
                'name' => trim($data['name']),
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
                'description' => $data['description'] ?? null,
            ])->save();
            // Phasing survives an edit when the line keeps its target, basis and amount.
            $kept = $budget->exists ? $budget->lines()->whereNotNull('phasing')->get()
                ->keyBy(fn ($l) => $l->target().'|'.$l->cost_centre_id.'|'.$l->basis.'|'.Money::toPaise((string) $l->amount)) : collect();
            $periods = count($budget->periods());
            $budget->lines()->delete();
            foreach ($lines as $i => $line) {
                $key = ($line['account_id'] ? 'l:'.$line['account_id'] : 'g:'.$line['account_group_id']).'|'.$line['cost_centre_id'].'|'.$line['basis'].'|'.Money::toPaise($line['amount']);
                $phasing = $line['phasing'] ?? $kept->get($key)?->phasing;
                $line['phasing'] = is_array($phasing) && count($phasing) === $periods ? $phasing : null;
                $budget->lines()->create(['line_no' => $i + 1] + $line);
            }

            return $budget->load('lines');
        });
    }

    /** A copy of the budget moved on by one year, named "<name> (next year)" unless a name is given. */
    public function copyToNextYear(Budget $budget, User $user, ?string $name = null): Budget
    {
        return $this->save(null, [
            'name' => $name ?: mb_substr($budget->name.' ('.$budget->from_date->copy()->addYearNoOverflow()->format('Y').')', 0, 100),
            'from_date' => $budget->from_date->copy()->addYearNoOverflow()->toDateString(),
            'to_date' => $budget->to_date->copy()->addYearNoOverflow()->toDateString(),
            'description' => $budget->description,
            'lines' => $budget->lines->map(fn (BudgetLine $l) => [
                'target' => $l->target(), 'cost_centre_id' => $l->cost_centre_id, 'basis' => $l->basis, 'amount' => (string) $l->amount,
                'phasing' => $l->phasing,
            ])->all(),
        ], $user);
    }

    /** Report date for a budget: today while it runs, else its last day (or first, before it starts). */
    public function defaultDate(Budget $budget): string
    {
        $today = now()->toDateString();

        return min(max($today, $budget->from_date->toDateString()), $budget->to_date->toDateString());
    }

    /**
     * Budget against actual for every line, as on $asOf.
     *
     * @return array{as_of: string, elapsed: int, days: int, rows: Collection}
     */
    public function report(Budget $budget, string $asOf): array
    {
        $from = $budget->from_date->toDateString();
        $asOf = min(max($asOf, $from), $budget->to_date->toDateString());
        $budget->loadMissing('lines.group', 'lines.account.group', 'lines.costCentre');
        $elapsed = (int) Carbon::parse($from)->diffInDays(Carbon::parse($asOf)) + 1;
        $days = $budget->days();

        $groupLedgers = $this->ledgersByGroup();
        $centres = $this->costCentreFamilies();
        $movements = DB::table('voucher_entries as e')
            ->join('vouchers as v', 'v.id', '=', 'e.voucher_id')
            ->where('v.company_id', $this->current->id())
            ->where('v.status', Voucher::POSTED)
            ->whereBetween('v.date', [$from, $asOf])
            ->groupBy('e.account_id', 'e.cost_centre_id')
            ->selectRaw('e.account_id, e.cost_centre_id, SUM(ROUND(e.debit * 100) - ROUND(e.credit * 100)) as net')
            ->get();
        $closing = $budget->lines->contains('basis', 'closing')
            ? $this->balances->forPeriod($this->balances->yearStart($asOf), $asOf)->mapWithKeys(fn ($b) => [$b->account->id => $b->closing])
            : collect();

        $rows = $budget->lines->map(function (BudgetLine $line) use ($groupLedgers, $centres, $movements, $closing, $elapsed, $days, $asOf) {
            $ledgers = $line->account_id ? [$line->account_id] : ($groupLedgers[$line->account_group_id] ?? []);
            $nature = $line->nature();
            $sign = in_array($nature, ['asset', 'expense'], true) ? 1 : -1;

            if ($line->basis === 'closing') {
                $actual = $sign * (int) collect($ledgers)->sum(fn ($id) => $closing[$id] ?? 0);
                $budgetToDate = Money::toPaise((string) $line->amount);
            } else {
                $family = $line->cost_centre_id ? ($centres[$line->cost_centre_id] ?? [$line->cost_centre_id]) : null;
                $actual = $sign * (int) round((float) $movements
                    ->filter(fn ($m) => in_array((int) $m->account_id, $ledgers, true) && ($family === null || in_array((int) $m->cost_centre_id, $family, true)))
                    ->sum('net'));
                $budgetToDate = $line->isPhased() ? $this->phasedToDate($line, $asOf) : intdiv(Money::toPaise((string) $line->amount) * $elapsed, $days);
            }
            $variance = $actual - $budgetToDate;

            return (object) [
                'line' => $line,
                'name' => $line->account_id ? $line->account->name : $line->group->name,
                'kind' => $line->account_id ? 'ledger' : 'group',
                'nature' => $nature,
                'budget' => Money::toPaise((string) $line->amount),
                'budget_to_date' => $budgetToDate,
                'actual' => $actual,
                'variance' => $variance,
                'percent' => $budgetToDate !== 0 ? round($actual * 100 / $budgetToDate, 1) : null,
                // Spending more, or earning less, than planned.
                'flag' => match (true) {
                    $nature === 'expense' && $variance > 0 => 'over',
                    $nature === 'income' && $variance < 0 => 'short',
                    default => null,
                },
            ];
        });

        return ['as_of' => $asOf, 'elapsed' => $elapsed, 'days' => $days, 'rows' => $rows];
    }

    /**
     * Save month amounts for nett lines: [line id => [paise strings per period]].
     * A line's annual amount becomes the sum of its months; an empty row clears the phasing.
     *
     * @param  array<int|string, array<int, ?string>>  $months
     */
    public function savePhasing(Budget $budget, array $months): Budget
    {
        $budget->loadMissing('lines');
        $count = count($budget->periods());
        $errors = [];
        $updates = [];
        foreach ($budget->lines as $line) {
            $row = $months[$line->id] ?? null;
            if ($row === null || $line->basis !== 'nett') {
                continue;
            }
            $row = array_values($row);
            if (collect($row)->every(fn ($v) => trim((string) $v) === '')) {
                $updates[$line->id] = null;

                continue;
            }
            if (count($row) !== $count) {
                $errors["months.{$line->id}"] = "Line {$line->line_no}: enter an amount for each of the {$count} months.";

                continue;
            }
            $paise = [];
            foreach ($row as $i => $value) {
                try {
                    $paise[] = Money::toPaise(trim((string) $value) === '' ? '0' : str_replace(',', '', (string) $value));
                } catch (\InvalidArgumentException) {
                    $errors["months.{$line->id}.{$i}"] = "Line {$line->line_no}, month ".($i + 1).': enter a valid amount.';
                }
            }
            if (count($paise) === $count) {
                $updates[$line->id] = $paise;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($budget, $updates) {
            foreach ($budget->lines as $line) {
                if (! array_key_exists($line->id, $updates)) {
                    continue;
                }
                $months = $updates[$line->id];
                $line->update($months === null
                    ? ['phasing' => null]
                    : ['phasing' => $months, 'amount' => Money::fromPaise(array_sum($months))]);
            }
            \App\Models\AuditLog::record('updated', $budget, null, ['phased_lines' => $budget->lines()->whereNotNull('phasing')->count()], "Monthly phasing of budget \"{$budget->name}\" saved");
        });

        return $budget->fresh('lines');
    }

    /** Budget to date of a phased line: whole months before the date's month, plus that month pro rata by days. */
    protected function phasedToDate(BudgetLine $line, string $asOf): int
    {
        $sum = 0;
        foreach ($line->budget->periods() as $i => [$from, $to]) {
            $amount = (int) ($line->phasing[$i] ?? 0);
            if ($to <= $asOf) {
                $sum += $amount;
            } elseif ($from <= $asOf) {
                $days = (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
                $sum += intdiv($amount * ((int) Carbon::parse($from)->diffInDays(Carbon::parse($asOf)) + 1), $days);
            }
        }

        return $sum;
    }

    /** Ledger ids under each group, including its sub-groups. */
    protected function ledgersByGroup(): array
    {
        $groups = AccountGroup::query()->get(['id', 'parent_id']);
        $own = Account::query()->get(['id', 'account_group_id'])->groupBy('account_group_id')->map(fn ($c) => $c->pluck('id')->all());
        $parents = $groups->keyBy('id');
        $out = [];
        foreach ($groups as $group) {
            $id = $group->id;
            while ($id) {
                $out[$id] = array_merge($out[$id] ?? [], $own[$group->id] ?? []);
                $id = $parents[$id]->parent_id;
            }
        }

        return $out;
    }

    /** Each cost centre with its sub-cost-centres. */
    protected function costCentreFamilies(): array
    {
        $centres = CostCentre::query()->get(['id', 'parent_id'])->keyBy('id');
        $out = [];
        foreach ($centres as $centre) {
            $id = $centre->id;
            while ($id && isset($centres[$id])) {
                $out[$id][] = $centre->id;
                $id = $centres[$id]->parent_id;
            }
        }

        return $out;
    }
}
