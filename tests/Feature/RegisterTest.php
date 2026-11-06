<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_register_by_month_and_drill_down(): void
    {
        $company = User::factory()->create()->companies()->create([
            'name' => 'Reg Co', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $group = fn (string $code) => $company->accountGroups()->where('code', $code)->value('id');
        $sales = $company->accounts()->create(['name' => 'Sales', 'account_group_id' => $group('SALES_ACCOUNTS'), 'account_type' => 'income']);
        $party = $company->accounts()->create(['name' => 'Pune Stores', 'account_group_id' => $group('SUNDRY_DEBTORS'), 'account_type' => 'assets', 'state_code' => '27']);
        $user = $company->user;

        foreach ([['2026-04-10', 1000], ['2026-04-20', 2000], ['2026-06-05', 500]] as [$date, $amount]) {
            $this->actingAs($user)->post(route('companies.gst-invoices.store', $company), [
                'type' => 'sales', 'date' => $date, 'party_account_id' => $party->id,
                'lines' => [['account_id' => $sales->id, 'taxable' => $amount, 'rate' => 18]],
            ])->assertSessionHasNoErrors();
        }

        // Day Book → Sales → Month-wise is the Sales Register.
        $months = $this->actingAs($user)->get(route('companies.vouchers.index', [$company, 'type' => 'sales', 'view' => 'monthly']))
            ->assertOk()->assertSee('Sales Register')->viewData('months');
        $this->assertCount(12, $months);
        $this->assertSame([2, 3000.0, 540.0, 3540.0], [$months[0]->count, $months[0]->taxable, $months[0]->tax, $months[0]->total]);
        $this->assertSame(0, $months[1]->count);
        $this->assertSame(590.0, $months[2]->total);

        // A month drills down to the voucher list for that month.
        $list = $this->actingAs($user)->get(route('companies.vouchers.index', [$company, 'type' => 'sales', 'from' => '2026-04-01', 'to' => '2026-04-30']))
            ->assertOk()->viewData('vouchers');
        $this->assertSame(2, $list->total());

        $this->actingAs($user)->get(route('companies.vouchers.index', [$company, 'type' => 'purchase', 'view' => 'monthly']))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('companies.vouchers.index', [$company, 'type' => 'sales', 'view' => 'monthly']))->assertForbidden();
    }
}
