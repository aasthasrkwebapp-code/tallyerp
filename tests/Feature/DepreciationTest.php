<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Machinery ₹1,00,000 at 15% → ₹15,000; Computers ₹60,000 at 40% → ₹24,000.
 * Furniture has no rate, so it isn't depreciated.
 */
class DepreciationTest extends TestCase
{
    use RefreshDatabase;

    public function test_wdv_depreciation_posted_once_a_year(): void
    {
        $company = User::factory()->create()->companies()->create([
            'name' => 'Asset Co', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $fixed = $company->accountGroups()->where('code', 'FIXED_ASSETS')->firstOrFail();
        $asset = fn (string $name, float $value) => $company->accounts()->create([
            'name' => $name, 'account_group_id' => $fixed->id, 'account_type' => 'assets', 'opening_balance' => $value,
        ]);
        $machinery = $asset('Machinery', 100000);
        $computers = $asset('Computers', 60000);
        $asset('Furniture', 20000);
        $user = $company->user;

        $this->actingAs($user)->put(route('companies.fixed-assets.update', $company), [
            'rates' => [$machinery->id => 15, $computers->id => 40],
        ])->assertSessionHasNoErrors();

        $assets = $this->actingAs($user)->get(route('companies.fixed-assets.index', $company))
            ->assertOk()->assertSee('Machinery')->viewData('assets')->keyBy(fn ($a) => $a->account->name);
        $this->assertSame([15000.0, 24000.0, 0.0], [$assets['Machinery']->depreciation, $assets['Computers']->depreciation, $assets['Furniture']->depreciation]);

        $this->actingAs($user)->post(route('companies.fixed-assets.post', $company), ['date' => '2027-03-31'])
            ->assertSessionHas('success', 'Depreciation of ₹ 39,000.00 posted as Journal #1.');

        $expense = $company->accounts()->where('name', 'Depreciation')->firstOrFail();
        $this->assertSame(39000.0, (float) $expense->entries()->sum('debit'));
        $this->assertSame(15000.0, (float) $machinery->entries()->sum('credit'));

        // After posting, book value is lower and a second post is refused.
        $after = $this->actingAs($user)->get(route('companies.fixed-assets.index', [$company, 'date' => '2027-03-31']))
            ->assertSee('is posted')->viewData('assets')->keyBy(fn ($a) => $a->account->name);
        $this->assertSame(85000.0, $after['Machinery']->value);
        $this->actingAs($user)->post(route('companies.fixed-assets.post', $company), ['date' => '2027-03-31'])->assertSessionHas('error');

        // Only fixed-asset ledgers can be given a rate.
        $cash = $company->accounts()->create(['name' => 'Cash', 'account_group_id' => $company->accountGroups()->where('code', 'CASH_IN_HAND')->value('id'), 'account_type' => 'assets']);
        $this->actingAs($user)->put(route('companies.fixed-assets.update', $company), ['rates' => [$cash->id => 10]])->assertNotFound();
    }
}
