<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\FinancialYear;
use App\Models\LeaveEncashment;
use App\Models\PayrollRun;
use App\Models\TaxDeclaration;
use App\Models\TdsReturn;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Form 16 (certificate of tax deducted from salary) for a financial year.
 *
 * Part A takes, for each quarter, the salary paid and the TDS deducted on
 * posted payrolls. Part B works out the tax: gross salary (pay heads, leave
 * encashment and any previous employer's salary), less section 10
 * exemptions, the standard deduction and professional tax, plus other income
 * and house property, less Chapter VI-A, then slab tax, the section 87A
 * rebate, surcharge and cess under the regime the employee chose. The
 * regimes' figures come from config erp.income_tax. All amounts are in paise.
 */
class Form16
{
    /** The year asked for, else the current one, else the latest. */
    public function year(?int $id): ?FinancialYear
    {
        return ($id ? FinancialYear::query()->find($id) : null)
            ?? FinancialYear::query()->where('is_current', true)->first()
            ?? FinancialYear::query()->orderByDesc('start_date')->first();
    }

    /** Salary of one employee for the year from posted payrolls and leave encashments. */
    public function salary(Employee $employee, FinancialYear $year): array
    {
        $start = Carbon::parse($year->start_date)->startOfMonth();
        $quarters = array_fill(1, 4, ['paid' => 0, 'tds' => 0]);
        $quarterOf = fn (Carbon $date) => intdiv(($date->year - $start->year) * 12 + $date->month - $start->month, 3) + 1;

        $lines = DB::table('payslip_lines as l')
            ->join('payslips as s', 's.id', '=', 'l.payslip_id')
            ->join('payroll_runs as r', 'r.id', '=', 's.payroll_run_id')
            ->join('pay_heads as h', 'h.id', '=', 'l.pay_head_id')
            ->where('s.employee_id', $employee->id)->where('r.status', 'posted')
            ->whereBetween('r.month', [$start->format('Y-m'), Carbon::parse($year->end_date)->format('Y-m')])
            ->orderBy('h.sort_order')->orderBy('h.id')
            ->get(['l.name', 'l.type', 'l.amount', 'h.system_key', 'r.month']);

        $heads = [];
        $pt = 0;
        $tds = 0;
        $months = [];
        foreach ($lines as $line) {
            $amount = Money::toPaise($line->amount);
            $quarter = $quarterOf(Carbon::parse($line->month.'-01'));
            $months[$line->month] = true;
            if ($line->type === 'earning') {
                $heads[$line->name] = ($heads[$line->name] ?? 0) + $amount;
                $quarters[$quarter]['paid'] += $amount;
            } elseif ($line->type === 'deduction' && $line->system_key === 'pt') {
                $pt += $amount;
            } elseif ($line->type === 'deduction' && $line->system_key === 'tds') {
                $tds += $amount;
                $quarters[$quarter]['tds'] += $amount;
            }
        }

        $encashed = 0;
        foreach (LeaveEncashment::query()->where('employee_id', $employee->id)->where('status', 'active')
            ->whereBetween('date', [$year->start_date->toDateString(), $year->end_date->toDateString()])->get() as $e) {
            $encashed += Money::toPaise($e->amount);
            $quarters[$quarterOf(Carbon::parse($e->date))]['paid'] += Money::toPaise($e->amount);
        }
        if ($encashed) {
            $heads['Leave encashment'] = $encashed;
        }

        return ['heads' => $heads, 'gross' => array_sum($heads), 'professional_tax' => $pt, 'tds' => $tds, 'quarters' => $quarters, 'months' => count($months)];
    }

    /** The whole Form 16 computation for one employee. */
    public function compute(Employee $employee, FinancialYear $year, ?TaxDeclaration $declaration = null): array
    {
        $declaration ??= TaxDeclaration::query()->where('employee_id', $employee->id)->where('financial_year_id', $year->id)->first()
            ?? new TaxDeclaration(['regime' => 'new']);
        $regime = $declaration->regime === 'old' ? 'old' : 'new';
        $rules = config("erp.income_tax.{$regime}");
        $limits = config('erp.income_tax.limits');
        $old = $regime === 'old';
        $d = fn (string $field) => Money::toPaise($declaration->{$field} ?? 0);
        $rupees = fn (int $r) => $r * 100;

        $salary = $this->salary($employee, $year);
        $previous = $d('previous_employer_salary');
        $gross = $salary['gross'] + $previous;
        $exemptions = $old ? min($gross, $d('hra_exemption') + $d('other_exemptions')) : 0;
        $standard = min($rupees($rules['standard_deduction']), max(0, $gross - $exemptions));
        $pt = $rules['professional_tax'] ? min($salary['professional_tax'], $rupees($limits['professional_tax'])) : 0;
        $salaryIncome = max(0, $gross - $exemptions - $standard - $pt);
        $houseProperty = $old ? -min($d('home_loan_interest'), $rupees($limits['home_loan_interest'])) : 0;
        $otherIncome = $d('other_income');
        $grossTotal = max(0, $salaryIncome + $houseProperty + $otherIncome);

        $chapter = [];
        if ($rules['chapter_via']) {
            $chapter = array_filter([
                '80C' => min($d('section_80c'), $rupees($limits['section_80c'])),
                '80CCD(1B)' => min($d('section_80ccd_1b'), $rupees($limits['section_80ccd_1b'])),
                '80D' => $d('section_80d'),
                'Other' => $d('other_deductions'),
            ]);
        }
        $chapterTotal = min($grossTotal, array_sum($chapter));
        $taxable = $this->roundTen($grossTotal - $chapterTotal);

        $age = $employee->date_of_birth ? (int) Carbon::parse($employee->date_of_birth)->diffInYears(Carbon::parse($year->end_date)) : null;
        $tax = $this->tax($taxable, $regime, $age);
        $tdsTotal = $salary['tds'] + $d('previous_employer_tds');

        return [
            'regime' => $regime, 'declaration' => $declaration, 'salary' => $salary, 'age' => $age,
            'previous_salary' => $previous, 'gross' => $gross, 'exemptions' => $exemptions, 'standard_deduction' => $standard,
            'professional_tax' => $pt, 'salary_income' => $salaryIncome, 'house_property' => $houseProperty, 'other_income' => $otherIncome,
            'gross_total' => $grossTotal, 'chapter_via' => $chapter, 'chapter_via_total' => $chapterTotal, 'taxable' => $taxable,
            'tax' => $tax, 'tds_this_employer' => $salary['tds'], 'tds_previous' => $d('previous_employer_tds'), 'tds' => $tdsTotal,
            'balance' => $tax['total'] - $tdsTotal,
            'ignored' => $old ? [] : array_values(array_filter(TaxDeclaration::OLD_REGIME_ONLY, fn ($f) => $d($f) > 0)),
        ];
    }

    /**
     * Tax on a taxable income (paise) under a regime.
     *
     * @return array{slabs: list<array{from:int, to:?int, rate:int, tax:int}>, on_income:int, rebate:int, surcharge_rate:int, surcharge:int, cess:int, total:int}
     */
    public function tax(int $taxable, string $regime, ?int $age = null): array
    {
        $rules = config("erp.income_tax.{$regime}");
        $slabs = $rules['slabs'];
        // A senior citizen's higher basic exemption (old regime) widens the nil band.
        $exempt = 0;
        foreach ($rules['senior_exemption'] ?? [] as $fromAge => $amount) {
            if ($age !== null && $age >= $fromAge) {
                $exempt = $amount;
            }
        }
        if ($exempt) {
            $slabs = array_values(array_filter($slabs, fn ($s) => $s[1] === 0 || $s[0] === null || $s[0] > $exempt));
            $slabs[0][0] = $exempt;
        }

        $rows = [];
        $onIncome = 0;
        $lower = 0;
        foreach ($slabs as [$upper, $rate]) {
            $to = $upper === null ? null : $upper * 100;
            $portion = max(0, min($taxable, $to ?? PHP_INT_MAX) - $lower);
            $slabTax = (int) round($portion * $rate / 100);
            $rows[] = ['from' => $lower, 'to' => $to, 'rate' => $rate, 'amount' => $portion, 'tax' => $slabTax];
            $onIncome += $slabTax;
            if ($to === null || $taxable <= $to) {
                break;
            }
            $lower = $to;
        }

        $limit = $rules['rebate_limit'] * 100;
        $rebate = 0;
        if ($taxable <= $limit) {
            $rebate = min($onIncome, $rules['rebate_max'] * 100);
        } elseif ($rules['rebate_marginal_relief'] && $onIncome > $taxable - $limit) {
            $rebate = $onIncome - ($taxable - $limit); // tax may not exceed the income above the rebate limit
        }
        $afterRebate = $onIncome - $rebate;

        $surchargeRate = 0;
        foreach ($rules['surcharge'] as [$above, $rate]) {
            if ($taxable > $above * 100) {
                $surchargeRate = $rate;
            }
        }
        $surcharge = (int) round($afterRebate * $surchargeRate / 100);
        $cess = (int) round(($afterRebate + $surcharge) * config('erp.income_tax.cess') / 100);

        return [
            'slabs' => $rows, 'on_income' => $onIncome, 'rebate' => $rebate, 'surcharge_rate' => $surchargeRate,
            'surcharge' => $surcharge, 'cess' => $cess, 'total' => $this->roundTen($afterRebate + $surcharge + $cess),
        ];
    }

    /** One row per employee paid in the year (or with a declaration). */
    public function summary(FinancialYear $year): Collection
    {
        $paid = DB::table('payslips as s')->join('payroll_runs as r', 'r.id', '=', 's.payroll_run_id')
            ->where('r.status', 'posted')->where('r.company_id', $year->company_id)
            ->whereBetween('r.month', [Carbon::parse($year->start_date)->format('Y-m'), Carbon::parse($year->end_date)->format('Y-m')])
            ->distinct()->pluck('s.employee_id');
        $declared = TaxDeclaration::query()->where('financial_year_id', $year->id)->get()->keyBy('employee_id');

        return Employee::query()->whereIn('id', $paid->merge($declared->keys())->unique())->orderBy('code')->get()
            ->map(fn (Employee $e) => (object) (['employee' => $e] + $this->compute($e, $year, $declared->get($e->id))));
    }

    /** @return array<int, TdsReturn> keyed by quarter */
    public function returns(FinancialYear $year): array
    {
        return TdsReturn::query()->where('financial_year_id', $year->id)->get()->keyBy('quarter')->all();
    }

    /** Quarter labels for a year, e.g. [1 => 'Q1 (Apr–Jun 2026)']. */
    public function quarterLabels(FinancialYear $year): array
    {
        $start = Carbon::parse($year->start_date)->startOfMonth();
        $labels = [];
        for ($q = 1; $q <= 4; $q++) {
            $from = $start->copy()->addMonths(($q - 1) * 3);
            $labels[$q] = "Q{$q} (".$from->format('M').'–'.$from->copy()->addMonths(2)->format('M Y').')';
        }

        return $labels;
    }

    /** Sections 288A / 288B: income and tax rounded to the nearest ten rupees. */
    protected function roundTen(int $paise): int
    {
        return (int) round(max(0, $paise) / 1000) * 1000;
    }
}
