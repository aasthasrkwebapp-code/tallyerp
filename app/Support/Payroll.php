<?php

namespace App\Support;

use App\Models\Employee;

/**
 * Statutory payroll calculations (simplified, monthly).
 *
 * - PF: 12% of basic, on basic capped at 15,000, from both employee and employer.
 * - ESI: when monthly gross is 21,000 or less: employee 0.75%, employer 3.25% (rounded up).
 * - Professional tax: 200 when gross is above 10,000 (varies by state; adjust here).
 */
class Payroll
{
    public const PF_RATE = 12;

    public const PF_WAGE_CEILING = 15000;

    public const ESI_LIMIT = 21000;

    public const ESI_EMPLOYEE_RATE = 0.75;

    public const ESI_EMPLOYER_RATE = 3.25;

    public const PT_THRESHOLD = 10000;

    public const PT_AMOUNT = 200;

    /**
     * @return array<string, float>
     */
    public static function calculate(Employee $employee, float $daysPayable, int $daysInMonth): array
    {
        $factor = $daysInMonth > 0 ? min($daysPayable, $daysInMonth) / $daysInMonth : 0;

        $basic = round((float) $employee->basic * $factor, 2);
        $hra = round((float) $employee->hra * $factor, 2);
        $other = round((float) $employee->other_allowances * $factor, 2);
        $gross = round($basic + $hra + $other, 2);

        $pf = $employee->pf_applicable ? round(min($basic, self::PF_WAGE_CEILING) * self::PF_RATE / 100) : 0.0;

        // ESI eligibility depends on the full monthly wage, not the pro-rated one.
        $esiCovered = $employee->esi_applicable && $employee->monthlyGross() <= self::ESI_LIMIT;
        $esiEmployee = $esiCovered ? ceil($gross * self::ESI_EMPLOYEE_RATE / 100) : 0.0;
        $esiEmployer = $esiCovered ? ceil($gross * self::ESI_EMPLOYER_RATE / 100) : 0.0;

        $pt = $employee->pt_applicable && $gross > self::PT_THRESHOLD ? (float) self::PT_AMOUNT : 0.0;

        return [
            'days_payable' => round(min($daysPayable, $daysInMonth), 2),
            'days_in_month' => $daysInMonth,
            'basic' => $basic,
            'hra' => $hra,
            'other_allowances' => $other,
            'gross' => $gross,
            'pf_employee' => (float) $pf,
            'esi_employee' => (float) $esiEmployee,
            'professional_tax' => $pt,
            'net_pay' => round($gross - $pf - $esiEmployee - $pt, 2),
            'pf_employer' => (float) $pf,
            'esi_employer' => (float) $esiEmployer,
        ];
    }
}
