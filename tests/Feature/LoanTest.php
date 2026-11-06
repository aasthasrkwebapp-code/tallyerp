<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ₹5,00,000 at 10% for 60 months → EMI ₹10,623.52; first month's interest ₹4,166.67.
 */
class LoanTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Loan Co', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $group = $this->company->accountGroups()->where('code', 'BANK_ACCOUNTS')->firstOrFail();
        $this->bank = $this->company->accounts()->create([
            'name' => 'HDFC Bank', 'account_group_id' => $group->id, 'account_type' => $group->nature,
        ]);
    }

    protected function takeLoan(array $overrides = [])
    {
        return $this->actingAs($this->company->user)->post(route('companies.loans.store', $this->company), $overrides + [
            'lender' => 'SBI', 'secured' => 1, 'principal' => 500000, 'annual_rate' => 10,
            'tenure_months' => 60, 'start_date' => '2026-05-01', 'received_in' => $this->bank->id,
        ]);
    }

    public function test_emi_and_schedule(): void
    {
        $this->assertSame(10623.52, Loan::emiFor(500000, 10, 60));
        $this->assertSame(1000.0, Loan::emiFor(12000, 0, 12));

        $this->takeLoan()->assertSessionHasNoErrors();
        $schedule = $this->company->loans()->firstOrFail()->schedule();

        $this->assertCount(60, $schedule);
        $this->assertSame(4166.67, $schedule[0]['interest']);
        $this->assertSame(6456.85, $schedule[0]['principal']);
        $this->assertSame(0.0, end($schedule)['balance']);
    }

    public function test_taking_a_loan_credits_the_loan_ledger_and_debits_the_bank(): void
    {
        $this->takeLoan()->assertSessionHasNoErrors()->assertSessionHas('success');

        $loan = $this->company->loans()->with('account.group')->firstOrFail();
        $this->assertSame('SECURED_LOANS', $loan->account->group->code);
        $this->assertSame(500000.0, $loan->outstanding());
        $this->assertSame(500000.0, (float) $this->bank->entries()->sum('debit'));
    }

    public function test_loan_from_an_earlier_year_is_an_opening_balance(): void
    {
        $this->takeLoan(['start_date' => '2025-01-01', 'received_in' => '', 'secured' => 0])->assertSessionHasNoErrors();

        $loan = $this->company->loans()->firstOrFail();
        $this->assertSame(500000.0, $loan->outstanding());
        $this->assertFalse($loan->account->entries()->exists());
    }

    public function test_paying_an_emi_splits_principal_and_interest(): void
    {
        $this->takeLoan();
        $loan = $this->company->loans()->firstOrFail();

        $this->actingAs($this->company->user)
            ->post(route('companies.loans.pay', [$this->company, $loan]), [
                'date' => '2026-06-01', 'paid_from' => $this->bank->id, 'amount' => 10623.52, 'interest' => 4166.67,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(493543.15, $loan->fresh()->outstanding());
        $interest = $this->company->accounts()->where('name', 'Interest on Loans')->firstOrFail();
        $this->assertSame(4166.67, (float) $interest->entries()->sum('debit'));
        $this->assertSame(10623.52, (float) $this->bank->entries()->sum('credit'));
    }

    public function test_cannot_repay_more_than_outstanding(): void
    {
        $this->takeLoan(['principal' => 1000]);
        $loan = $this->company->loans()->firstOrFail();

        $this->actingAs($this->company->user)
            ->post(route('companies.loans.pay', [$this->company, $loan]), [
                'date' => '2026-06-01', 'paid_from' => $this->bank->id, 'amount' => 2000, 'interest' => 0,
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_pages_render_and_other_users_are_blocked(): void
    {
        $this->takeLoan();
        $loan = $this->company->loans()->firstOrFail();
        $user = $this->company->user;

        $this->actingAs($user)->get(route('companies.loans.index', $this->company))->assertOk()->assertSee('SBI');
        $this->actingAs($user)->get(route('companies.loans.create', $this->company))->assertOk();
        $this->actingAs($user)->get(route('companies.loans.show', [$this->company, $loan]))->assertOk()->assertSee('10,623.52');

        $this->actingAs(User::factory()->create())->get(route('companies.loans.index', $this->company))->assertForbidden();
    }
}
