<?php

namespace Tests\Feature\Search;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Employee;
use App\Models\Party;
use App\Models\StockItem;
use App\Models\Unit;
use App\Services\Billing\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $customer;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->customer = Account::create(['name' => 'Zenith Retail', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $this->customer->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'regular', 'gstin' => '27AAACZ1234F1Z5']);
        StockItem::create([
            'name' => 'Zenith Chair', 'code' => 'ZC-01', 'unit_id' => Unit::where('symbol', 'Nos')->value('id'), 'hsn_sac' => '9401', 'gst_rate' => '18',
            'sales_account_id' => Account::idFor('sales'), 'purchase_account_id' => Account::idFor('purchase'),
            'tracking' => 'serial', 'opening_quantity' => '2', 'opening_value' => '2000', 'opening_serials' => "ZSN-100\nZSN-101",
        ]);
        Employee::create(['code' => 'E-77', 'name' => 'Zenith Kumar', 'date_of_joining' => '2026-04-01']);
    }

    public function test_finds_matches_across_modules(): void
    {
        app(OrderService::class)->save(null, 'sales_order', [
            'date' => '2026-05-01', 'party_account_id' => $this->customer->id,
            'items' => [['description' => 'Chairs', 'quantity' => '1', 'rate' => '100']],
        ], $this->owner);

        $this->actingAs($this->owner)->get(route('search', ['q' => 'zenith']))->assertOk()
            ->assertSee('Ledgers')->assertSee('Zenith Retail')
            ->assertSee('Stock items')->assertSee('Zenith Chair')
            ->assertSee('Quotations &amp; orders', false)->assertSee('SO/0001')
            ->assertSee('Employees')->assertSee('Zenith Kumar')
            ->assertSee(route('ledgers.show', $this->customer));

        // GSTIN, item code and serial numbers match too.
        $this->actingAs($this->owner)->get(route('search', ['q' => '27AAACZ']))->assertSee('Zenith Retail');
        $this->actingAs($this->owner)->get(route('search', ['q' => 'zc-01']))->assertSee('Zenith Chair');
        $this->actingAs($this->owner)->get(route('search', ['q' => 'zsn-101']))->assertSee('Serial ZSN-101')->assertDontSee('Serial ZSN-100');
    }

    public function test_short_or_unmatched_terms(): void
    {
        $this->actingAs($this->owner)->get(route('search'))->assertOk()->assertSee('at least two characters');
        $this->actingAs($this->owner)->get(route('search', ['q' => 'z']))->assertOk()->assertDontSee('Zenith Retail');
        $this->actingAs($this->owner)->get(route('search', ['q' => 'nothing-like-this']))->assertOk()->assertSee('Nothing matches');
        // LIKE wildcards are taken literally.
        $this->actingAs($this->owner)->get(route('search', ['q' => '%%']))->assertOk()->assertSee('Nothing matches');
    }

    public function test_groups_follow_permissions(): void
    {
        $inventory = $this->addMember($this->company, 'inventory');
        $this->actingAs($inventory)->get(route('search', ['q' => 'zenith']))->assertOk()
            ->assertSee('Zenith Chair')->assertSee('Zenith Retail') // inventory role has masters.view
            ->assertDontSee('Zenith Kumar');

        $employee = $this->addMember($this->company, 'employee');
        $this->actingAs($employee)->get(route('search', ['q' => 'zenith']))->assertOk()
            ->assertSee('Zenith Kumar')->assertDontSee('Zenith Chair')->assertDontSee('Zenith Retail');
    }

    public function test_other_companies_are_not_searched(): void
    {
        [, $other] = $this->createCompanyWithOwner(['name' => 'Other Co'], $this->owner);
        Account::create(['name' => 'Zenith Elsewhere', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party']);

        $this->useCompany($this->company);
        $this->actingAs($this->owner)->get(route('search', ['q' => 'zenith']))->assertOk()
            ->assertSee('Zenith Retail')->assertDontSee('Zenith Elsewhere');
    }

    public function test_json_response_and_topbar_box(): void
    {
        $this->actingAs($this->owner)->getJson(route('search', ['q' => 'zenith']))->assertOk()
            ->assertJsonPath('q', 'zenith')->assertJsonFragment(['title' => 'Zenith Retail']);
        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->assertSee('id="global-search"', false)
            ->assertSee('data-search-suggest="'.route('search').'"', false);

        // The dropdown reads each group's label, icon and rows (title, meta, url).
        $json = $this->getJson(route('search', ['q' => 'zenith']))->json('groups.0');
        $this->assertSame(['label', 'icon', 'rows', 'more', 'truncated'], array_keys($json));
        $this->assertSame(['title', 'meta', 'url'], array_keys($json['rows'][0]));
        $this->get(route('search'))->assertSee('data-search-suggest', false);
    }
}
