<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditTermsTest extends TestCase
{
    use RefreshDatabase;

    public function test_overdue_bills_and_credit_limit(): void
    {
        $company = User::factory()->create()->companies()->create([
            'name' => 'Credit Co', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $group = fn (string $code) => $company->accountGroups()->where('code', $code)->value('id');
        $sales = $company->accounts()->create(['name' => 'Sales', 'account_group_id' => $group('SALES_ACCOUNTS'), 'account_type' => 'income']);
        $user = $company->user;

        // Ledger form saves credit terms.
        $this->actingAs($user)->post(route('companies.accounts.store', $company), [
            'name' => 'Pune Stores', 'account_group_id' => $group('SUNDRY_DEBTORS'), 'opening_balance_type' => 'dr',
            'state_code' => '27', 'credit_days' => 30, 'credit_limit' => 10000,
        ])->assertSessionHasNoErrors();
        $party = $company->accounts()->where('name', 'Pune Stores')->firstOrFail();
        $this->assertSame([30, '10000.00'], [$party->credit_days, $party->credit_limit]);

        $sell = fn (string $date, float $amount) => $this->actingAs($user)->post(route('companies.gst-invoices.store', $company), [
            'type' => 'sales', 'date' => $date, 'party_account_id' => $party->id,
            'lines' => [['account_id' => $sales->id, 'taxable' => $amount, 'rate' => 0]],
        ]);

        $sell('2026-05-01', 6000)->assertSessionHasNoErrors()->assertSessionMissing('warning');
        // 6,000 + 5,000 = 11,000 > 10,000
        $sell('2026-06-20', 5000)->assertSessionHas('warning', 'Pune Stores now owes ₹ 11,000.00, above the credit limit of ₹ 10,000.00.');

        // On 1 July: the May bill (61 days) is overdue, the June one (11 days) isn't.
        $row = $this->actingAs($user)->get(route('companies.outstanding', [$company, 'receivables', 'to' => '2026-07-01']))
            ->assertOk()->assertSee('Over limit')
            ->viewData('parties')->first();
        $this->assertSame(6000.0, $row->overdue);
        $this->assertTrue($row->over_limit);

        // Without credit days nothing is overdue.
        $party->update(['credit_days' => null, 'credit_limit' => null]);
        $row = $this->actingAs($user)->get(route('companies.outstanding', [$company, 'receivables', 'to' => '2026-07-01']))->viewData('parties')->first();
        $this->assertSame([0.0, false], [$row->overdue, $row->over_limit]);
    }
}
