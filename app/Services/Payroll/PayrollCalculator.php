<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayHead;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Works out one employee's payslip for a month. Amounts are integer paise.
 *
 * Earnings marked "prorate" are reduced in proportion to paid days.
 * PF = rate % of earned basic, limited to the PF wage ceiling, rounded to the rupee.
 * ESI = rate % of earned gross, rounded up to the rupee, when the monthly gross
 * in the salary structure is within the ESI wage limit.
 * Professional tax comes from the slab of the company's state.
 * Overtime = the month's overtime hours × the employee's hourly rate.
 * Loan recovery = the instalments due, limited to what is left of the net pay.
 */
class PayrollCalculator
{
    /**
     * @param  Collection<int, PayHead>  $heads  active pay heads, in payslip order
     * @return array{lines: array<int, array{head: PayHead, amount:int}>, gross:int, deductions:int, employer:int, net:int}
     */
    public function calculate(Employee $employee, Collection $heads, int $daysInMonth, string $paidDays, int $month, ?string $state,
        string $overtimeHours = '0', int $loanDue = 0): array
    {
        $structure = $employee->payHeads->keyBy('pay_head_id');
        $loanHead = $heads->first(fn (PayHead $h) => $h->type === 'deduction' && $h->calculation === 'loan');
        $applies = fn (PayHead $h) => $h->calculation === 'loan' ? false : $structure->has($h->id) && match ($h->calculation) {
            'pf' => $employee->pf_applicable,
            'esi' => $employee->esi_applicable,
            'pt' => $employee->pt_applicable,
            default => true,
        };
        $paidTenths = Decimal::toScaled($paidDays, 1);
        $prorate = fn (int $paise) => Decimal::mulDiv($paise, $paidTenths, $daysInMonth * 10);
        $flat = fn (PayHead $h) => Money::toPaise($structure[$h->id]->amount ?? 0);
        $percent = fn (int $base, PayHead $h) => Decimal::mulDiv($base, Decimal::toScaled($h->rate ?? 0, 3), 100000);

        $lines = [];
        $basicFull = 0;
        $basicEarned = 0;
        $grossFull = 0;

        // Earnings: basic first, so percentage heads can use it.
        $earnings = $heads->where('type', 'earning')->filter($applies)->sortByDesc('is_basic');
        foreach ($earnings as $h) {
            [$full, $earned] = match ($h->calculation) {
                'percent_of_basic' => [$percent($basicFull, $h), $percent($basicEarned, $h)],
                'overtime' => [0, Decimal::mulDiv($flat($h), Decimal::toScaled($overtimeHours, 2), 100)],
                default => [$flat($h), $h->prorate ? $prorate($flat($h)) : $flat($h)],
            };
            if ($h->is_basic) {
                $basicFull += $full;
                $basicEarned += $earned;
            }
            $grossFull += $full;
            $lines[$h->id] = ['head' => $h, 'amount' => $earned];
        }
        $gross = array_sum(array_column($lines, 'amount'));

        $pfWage = min($basicEarned, (int) config('erp.payroll.pf_wage_ceiling') * 100);
        $esiCovered = $grossFull > 0 && $grossFull <= (int) config('erp.payroll.esi_wage_limit') * 100;

        foreach ($heads->whereIn('type', ['deduction', 'employer'])->filter($applies) as $h) {
            $amount = match ($h->calculation) {
                'pf' => $this->roundRupee($percent($pfWage, $h)),
                'esi' => $esiCovered ? $this->ceilRupee($percent($gross, $h)) : 0,
                'pt' => $this->professionalTax($gross, $month, $state),
                'percent_of_basic' => $percent($basicEarned, $h),
                default => $h->prorate ? $prorate($flat($h)) : $flat($h),
            };
            $lines[$h->id] = ['head' => $h, 'amount' => $amount];
        }

        // Loans last, so the recovery never takes net pay below zero.
        if ($loanHead && $loanDue > 0) {
            $left = $gross - array_sum(array_map(fn ($l) => $l['head']->type === 'deduction' ? $l['amount'] : 0, $lines));
            $lines[$loanHead->id] = ['head' => $loanHead, 'amount' => max(0, min($loanDue, $left))];
        }

        $sum = fn (string $type) => array_sum(array_map(fn ($l) => $l['head']->type === $type ? $l['amount'] : 0, $lines));
        $deductions = $sum('deduction');

        return [
            'lines' => array_values($lines),
            'gross' => $gross,
            'deductions' => $deductions,
            'employer' => $sum('employer'),
            'net' => $gross - $deductions,
        ];
    }

    /** Monthly professional tax in paise for the state's slab (0 where no slab is configured). */
    public function professionalTax(int $grossPaise, int $month, ?string $state): int
    {
        $slabs = config('erp.payroll.professional_tax.'.$state);
        if (! $slabs || $grossPaise <= 0) {
            return 0;
        }
        foreach ($slabs as $slab) {
            if ($slab[0] === null || $grossPaise <= $slab[0] * 100) {
                $tax = $month === 2 && isset($slab[2]) ? $slab[2] : $slab[1];

                return Money::toPaise((string) $tax);
            }
        }

        return 0;
    }

    protected function roundRupee(int $paise): int
    {
        return Decimal::roundDiv($paise, 100) * 100;
    }

    protected function ceilRupee(int $paise): int
    {
        return (int) ceil($paise / 100) * 100;
    }
}
