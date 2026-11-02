<?php

namespace App\Services\Payroll;

use App\Models\PayHead;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Files made from a posted payroll run: the bank transfer list of net pay, the
 * EPFO electronic challan cum return (ECR) and the ESIC monthly contribution list.
 *
 * Each returns the rows that can go in the file and the employees left out with the
 * reason (no bank account, no UAN, no ESI number), so nothing is dropped silently.
 */
class PayrollFiles
{
    /** ECR version 2 field separator. */
    public const ECR_SEPARATOR = '#~#';

    /** EPS: 8.33% of wages up to the ceiling, not for members aged 58 or more. */
    public const EPS_RATE = '8.33';

    public const EPS_AGE_LIMIT = 58;

    /**
     * Net pay per employee for a bank upload.
     *
     * @return array{rows: Collection, skipped: Collection, total: int}
     *   rows: object{payslip, code, name, bank, account, ifsc, amount (paise)}
     */
    public function bankTransfer(PayrollRun $run): array
    {
        $rows = collect();
        $skipped = collect();
        foreach ($this->payslips($run) as $slip) {
            $amount = Money::toPaise($slip->net);
            if ($amount <= 0) {
                continue;
            }
            $e = $slip->employee;
            $account = preg_replace('/\s+/', '', (string) $e->bank_account);
            $ifsc = strtoupper(trim((string) $e->ifsc));
            $reason = match (true) {
                $account === '' => 'No bank account number',
                ! preg_match('/^[0-9A-Za-z]{6,20}$/', $account) => 'Bank account number is not valid',
                $ifsc === '' => 'No IFSC',
                ! preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc) => 'IFSC is not valid',
                default => null,
            };
            if ($reason) {
                $skipped->push((object) ['payslip' => $slip, 'reason' => $reason, 'amount' => $amount]);

                continue;
            }
            $rows->push((object) [
                'payslip' => $slip, 'code' => $slip->employee_code, 'name' => $slip->employee_name,
                'bank' => (string) $e->bank_name, 'account' => $account, 'ifsc' => $ifsc, 'amount' => $amount,
            ]);
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'total' => $rows->sum('amount')];
    }

    /**
     * EPFO ECR v2 rows, amounts in whole rupees.
     *
     * Gross wages are the payslip gross; EPF wages the PF wage the contribution was worked on
     * (earned basic up to the ceiling); EPS and EDLI wages are EPF wages up to the ceiling,
     * with EPS nil from the month the member turns 58. EPS is 8.33% of EPS wages and the
     * employer's balance goes to EPF. NCP days are the unpaid days.
     *
     * @return array{rows: Collection, skipped: Collection, totals: array<string, int>}
     *   rows: object{payslip, uan, name, gross, epf_wages, eps_wages, edli_wages, epf, eps, diff, ncp, refund}
     */
    public function ecr(PayrollRun $run): array
    {
        $ceiling = (int) config('erp.payroll.pf_wage_ceiling');
        $heads = PayHead::query()->get()->keyBy('id');
        $rows = collect();
        $skipped = collect();
        foreach ($this->payslips($run) as $slip) {
            $amounts = $this->byHead($slip, $heads);
            $employeePf = $amounts['pf_deduction'];
            $employerPf = $amounts['pf_employer'];
            if ($employeePf === 0 && $employerPf === 0) {
                continue;
            }
            $uan = preg_replace('/\D/', '', (string) $slip->employee->uan);
            if (strlen($uan) !== 12) {
                $skipped->push((object) ['payslip' => $slip, 'reason' => $uan === '' ? 'No UAN' : 'UAN must be 12 digits', 'amount' => $employeePf]);

                continue;
            }
            $epfWages = min($this->rupees($amounts['basic']), $ceiling);
            $dob = $slip->employee->date_of_birth;
            $over58 = $dob && $dob->copy()->addYears(self::EPS_AGE_LIMIT)->startOfMonth()->lte($run->monthStart());
            $epsWages = $over58 ? 0 : min($epfWages, $ceiling);
            $employer = $this->rupees($employerPf);
            $eps = min((int) round($epsWages * (float) self::EPS_RATE / 100), $employer);
            $days = (int) $slip->days_in_month;
            $rows->push((object) [
                'payslip' => $slip,
                'uan' => $uan,
                'name' => $this->ecrName($slip->employee_name),
                'gross' => $this->rupees(Money::toPaise($slip->gross)),
                'epf_wages' => $epfWages,
                'eps_wages' => $epsWages,
                'edli_wages' => min($epfWages, $ceiling),
                'epf' => $this->rupees($employeePf),
                'eps' => $eps,
                'diff' => $employer - $eps,
                'ncp' => max(0, (int) round($days - (float) $slip->paid_days)),
                'refund' => 0,
            ]);
        }

        $totals = [];
        foreach (['gross', 'epf_wages', 'eps_wages', 'edli_wages', 'epf', 'eps', 'diff', 'ncp'] as $key) {
            $totals[$key] = $rows->sum($key);
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'totals' => $totals];
    }

    /** The ECR text file: one member per line, fields joined by #~#. */
    public function ecrText(array $ecr): string
    {
        return $ecr['rows']->map(fn ($r) => implode(self::ECR_SEPARATOR, [
            $r->uan, $r->name, $r->gross, $r->epf_wages, $r->eps_wages, $r->edli_wages, $r->epf, $r->eps, $r->diff, $r->ncp, $r->refund,
        ]))->implode("\n").($ecr['rows']->isNotEmpty() ? "\n" : '');
    }

    /**
     * ESIC monthly contribution rows: IP number, name, days paid, wages (whole rupees).
     * Reason code 0 (none) when days were paid, 1 (on leave) when none were.
     *
     * @return array{rows: Collection, skipped: Collection, totals: array<string, int>}
     */
    public function esi(PayrollRun $run): array
    {
        $heads = PayHead::query()->get()->keyBy('id');
        $rows = collect();
        $skipped = collect();
        foreach ($this->payslips($run) as $slip) {
            $amounts = $this->byHead($slip, $heads);
            if ($amounts['esi_deduction'] === 0 && $amounts['esi_employer'] === 0) {
                continue;
            }
            $ip = preg_replace('/\D/', '', (string) $slip->employee->esi_number);
            if (strlen($ip) < 10 || strlen($ip) > 17) {
                $skipped->push((object) ['payslip' => $slip, 'reason' => $ip === '' ? 'No ESI number' : 'ESI number must be 10 to 17 digits', 'amount' => $amounts['esi_deduction']]);

                continue;
            }
            $days = (int) floor((float) $slip->paid_days);
            $rows->push((object) [
                'payslip' => $slip, 'ip' => $ip, 'name' => $slip->employee_name, 'days' => $days,
                'wages' => $this->rupees(Money::toPaise($slip->gross)),
                'employee' => $amounts['esi_deduction'], 'employer' => $amounts['esi_employer'],
                'reason' => $days > 0 ? 0 : 1,
            ]);
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'totals' => [
            'wages' => $rows->sum('wages'), 'employee' => $rows->sum('employee'), 'employer' => $rows->sum('employer'),
        ]];
    }

    /** @return Collection<int, Payslip> */
    protected function payslips(PayrollRun $run): Collection
    {
        return $run->payslips()->with('employee', 'lines')->orderBy('employee_code')->get();
    }

    /** Basic earned and the PF / ESI amounts on a payslip, in paise. */
    protected function byHead(Payslip $slip, Collection $heads): array
    {
        $out = ['basic' => 0, 'pf_deduction' => 0, 'pf_employer' => 0, 'esi_deduction' => 0, 'esi_employer' => 0];
        foreach ($slip->lines as $line) {
            $head = $heads[$line->pay_head_id] ?? null;
            if (! $head) {
                continue;
            }
            $amount = Money::toPaise($line->amount);
            if ($head->is_basic) {
                $out['basic'] += $amount;
            }
            if (in_array($head->calculation, ['pf', 'esi'], true) && in_array($head->type, ['deduction', 'employer'], true)) {
                $out[$head->calculation.'_'.$head->type] += $amount;
            }
        }

        return $out;
    }

    protected function rupees(int $paise): int
    {
        return intdiv($paise + 50, 100);
    }

    /** Member names in the ECR: letters, spaces and dots only, upper case. */
    protected function ecrName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z. ]/', ' ', strtoupper($name))));
    }
}
