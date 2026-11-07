<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\Loan;
use App\Models\Voucher;
use App\Support\Books;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Loans taken: a ledger under Loans (Liability), EMI schedule, and EMI payments
 * split into principal (reduces the loan) and interest (an expense).
 */
class LoanController extends Controller
{
    public function index(Company $company)
    {
        $this->authorizeCompany($company);

        $loans = $company->loans()->with('account')->orderBy('start_date')->get();

        return view('loans.index', compact('company', 'loans'));
    }

    public function create(Company $company)
    {
        $this->authorizeCompany($company);

        return view('loans.create', ['company' => $company, 'banks' => $this->cashAndBank($company)]);
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        [$fyStart, $fyEnd] = (new Books($company))->financialYear();
        $data = $request->validate([
            'lender' => ['required', 'string', 'max:255'],
            'secured' => ['required', 'boolean'],
            'principal' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'annual_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'tenure_months' => ['required', 'integer', 'min:1', 'max:600'],
            'start_date' => ['required', 'date', 'before_or_equal:'.$fyEnd->toDateString()],
            'received_in' => ['nullable', Rule::in($this->cashAndBank($company)->pluck('id'))],
        ]);

        // Received this year: a receipt into the bank. Taken earlier: an opening balance.
        $receivedThisYear = \Carbon\CarbonImmutable::parse($data['start_date'])->gte($fyStart);
        if ($receivedThisYear && empty($data['received_in'])) {
            throw ValidationException::withMessages(['received_in' => 'Choose the bank or cash account the loan was received in.']);
        }

        $loan = DB::transaction(function () use ($company, $data, $receivedThisYear) {
            $group = $company->accountGroups()->where('code', $data['secured'] ? 'SECURED_LOANS' : 'UNSECURED_LOANS')->firstOrFail();
            $account = $company->accounts()->create([
                'name' => 'Loan - '.$data['lender'].' ('.\Carbon\CarbonImmutable::parse($data['start_date'])->format('M Y').')',
                'account_group_id' => $group->id,
                'account_type' => $group->nature,
                'opening_balance' => $receivedThisYear ? 0 : $data['principal'],
                'opening_balance_type' => 'cr',
            ]);

            $loan = $company->loans()->create([
                'account_id' => $account->id,
                'lender' => $data['lender'],
                'principal' => $data['principal'],
                'annual_rate' => $data['annual_rate'],
                'tenure_months' => $data['tenure_months'],
                'start_date' => $data['start_date'],
                'emi' => Loan::emiFor((float) $data['principal'], (float) $data['annual_rate'], (int) $data['tenure_months']),
            ]);

            if ($receivedThisYear) {
                $this->post($company, 'receipt', $data['start_date'], 'Loan received from '.$data['lender'], [
                    [(int) $data['received_in'], (float) $data['principal'], 0],
                    [$account->id, 0, (float) $data['principal']],
                ]);
            }

            return $loan;
        });

        return redirect()->route('companies.loans.show', [$company, $loan])
            ->with('success', 'Loan saved. EMI ₹ '.inr($loan->emi).' for '.$loan->tenure_months.' months.');
    }

    public function show(Company $company, Loan $loan)
    {
        $this->authorizeCompany($company);
        $this->owns($company, $loan);

        $loan->load('account');
        $payments = $loan->account->entries()->with('voucher')->where('debit', '>', 0)->get()
            ->sortBy(fn ($e) => $e->voucher->date)->values();

        return view('loans.show', [
            'company' => $company,
            'loan' => $loan,
            'outstanding' => $loan->outstanding(),
            'payments' => $payments,
            'banks' => $this->cashAndBank($company),
        ]);
    }

    /**
     * Pay an instalment: principal reduces the loan, interest is an expense.
     */
    public function pay(Request $request, Company $company, Loan $loan)
    {
        $this->authorizeCompany($company);
        $this->owns($company, $loan);

        [$fyStart, $fyEnd] = (new Books($company))->financialYear();
        $outstanding = $loan->outstanding();

        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:'.$fyStart->toDateString(), 'before_or_equal:'.$fyEnd->toDateString()],
            'paid_from' => ['required', Rule::in($this->cashAndBank($company)->pluck('id'))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'interest' => ['required', 'numeric', 'min:0', 'lte:amount'],
        ], ['interest.lte' => 'Interest can\'t be more than the amount paid.']);

        $principal = round((float) $data['amount'] - (float) $data['interest'], 2);
        if ($principal > $outstanding + 0.005) {
            throw ValidationException::withMessages(['amount' => 'Only ₹ '.inr($outstanding).' is outstanding on this loan.']);
        }

        DB::transaction(function () use ($company, $loan, $data, $principal) {
            $interestLedger = $company->systemLedger('LOAN_INTEREST', 'Interest on Loans', 'INDIRECT_EXPENSES');
            $this->post($company, 'payment', $data['date'], 'EMI to '.$loan->lender, array_values(array_filter([
                $principal > 0 ? [$loan->account_id, $principal, 0] : null,
                (float) $data['interest'] > 0 ? [$interestLedger->id, (float) $data['interest'], 0] : null,
                [(int) $data['paid_from'], 0, (float) $data['amount']],
            ])));
        });

        return back()->with('success', 'EMI of ₹ '.inr($data['amount']).' recorded: principal ₹ '.inr($principal).', interest ₹ '.inr($data['interest']).'.');
    }

    public function destroy(Company $company, Loan $loan)
    {
        $this->authorizeCompany($company);
        $this->owns($company, $loan);

        if ($loan->account->entries()->exists()) {
            return back()->with('error', 'This loan has vouchers. Delete those first, or keep the loan for the record.');
        }

        DB::transaction(function () use ($loan) {
            $account = $loan->account;
            $loan->delete();
            $account->delete();
        });

        return redirect()->route('companies.loans.index', $company)->with('success', 'Loan deleted.');
    }

    /**
     * @param  array<int, array{0: int, 1: float, 2: float}>  $lines  [account id, debit, credit]
     */
    protected function post(Company $company, string $type, string $date, string $narration, array $lines): Voucher
    {
        $voucher = $company->vouchers()->create([
            'type' => $type,
            'number' => Voucher::nextNumber($company, $type, $date),
            'date' => $date,
            'narration' => $narration,
            'amount' => round(array_sum(array_column($lines, 1)), 2),
        ]);
        $voucher->entries()->createMany(array_map(fn ($l) => ['account_id' => $l[0], 'debit' => round($l[1], 2), 'credit' => round($l[2], 2)], $lines));

        return $voucher;
    }

    protected function cashAndBank(Company $company)
    {
        return (new Books($company))->cashAndBankLedgers();
    }

    protected function owns(Company $company, Loan $loan): void
    {
        abort_unless((int) $loan->company_id === (int) $company->id, 404);
    }
}
