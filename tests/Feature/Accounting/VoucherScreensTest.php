<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Voucher;
use App\Models\VoucherType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function bank(): Account
    {
        return Account::create([
            'name' => 'ICICI Bank', 'account_group_id' => AccountGroup::idFor('bank_accounts'),
            'account_type' => 'bank', 'opening_balance' => 0, 'opening_balance_type' => 'Dr',
        ]);
    }

    public function test_payment_voucher_can_be_entered_through_the_form(): void
    {
        [$owner] = $this->createCompanyWithOwner();
        $bank = $this->bank();
        $type = VoucherType::where('base_type', 'payment')->first();

        $this->actingAs($owner)->get("/vouchers/create?type={$type->id}")->assertOk()->assertSee('Payment voucher');

        $response = $this->actingAs($owner)->post('/vouchers', [
            'voucher_type_id' => $type->id,
            'date' => '2026-07-01',
            'reference' => 'CHQ 000123',
            'narration' => 'Office electricity',
            'action' => 'post',
            'entries' => [
                ['side' => 'Dr', 'account_id' => Account::idFor('bank_charges'), 'amount' => '1,250.50'],
                ['side' => 'Cr', 'account_id' => $bank->id, 'amount' => '1250.50'],
            ],
        ]);

        $voucher = Voucher::firstOrFail();
        $response->assertRedirect(route('vouchers.show', $voucher));
        $this->assertSame('PY/0001', $voucher->voucher_number);
        $this->assertSame('1250.50', $voucher->total);

        $this->actingAs($owner)->get("/vouchers/{$voucher->id}")->assertOk()->assertSee('PY/0001')->assertSee('1,250.50');
        $this->actingAs($owner)->get('/vouchers')->assertOk()->assertSee('PY/0001');
        $this->actingAs($owner)->get("/vouchers/{$voucher->id}/edit")->assertOk();
    }

    public function test_unbalanced_form_returns_errors_and_keeps_input(): void
    {
        [$owner] = $this->createCompanyWithOwner();
        $type = VoucherType::where('base_type', 'journal')->first();

        $this->actingAs($owner)->from("/vouchers/create?type={$type->id}")->post('/vouchers', [
            'voucher_type_id' => $type->id,
            'date' => '2026-07-01',
            'action' => 'post',
            'entries' => [
                ['side' => 'Dr', 'account_id' => Account::idFor('bank_charges'), 'amount' => '100'],
                ['side' => 'Cr', 'account_id' => Account::idFor('capital'), 'amount' => '90'],
            ],
        ])->assertRedirect("/vouchers/create?type={$type->id}")->assertSessionHasErrors('entries');

        $this->assertSame(0, Voucher::count());
    }

    public function test_draft_save_post_cancel_flow(): void
    {
        [$owner] = $this->createCompanyWithOwner();
        $type = VoucherType::where('base_type', 'journal')->first();
        $lines = [
            ['side' => 'Dr', 'account_id' => Account::idFor('bank_charges'), 'amount' => '100'],
            ['side' => 'Cr', 'account_id' => Account::idFor('capital'), 'amount' => '100'],
        ];

        $this->actingAs($owner)->post('/vouchers', ['voucher_type_id' => $type->id, 'date' => '2026-07-01', 'action' => 'draft', 'entries' => $lines]);
        $voucher = Voucher::firstOrFail();
        $this->assertSame(Voucher::DRAFT, $voucher->status);

        $this->actingAs($owner)->post("/vouchers/{$voucher->id}/post")->assertRedirect();
        $this->assertSame(Voucher::POSTED, $voucher->fresh()->status);

        $this->actingAs($owner)->delete("/vouchers/{$voucher->id}")->assertSessionHasErrors('voucher');

        $this->actingAs($owner)->post("/vouchers/{$voucher->id}/cancel", ['reason' => 'Posted twice'])->assertRedirect();
        $this->assertSame(Voucher::CANCELLED, $voucher->fresh()->status);
        $this->actingAs($owner)->get("/vouchers/{$voucher->id}/edit")->assertForbidden();
    }

    public function test_user_role_can_create_but_not_alter_posted_vouchers(): void
    {
        [$owner, $company] = $this->createCompanyWithOwner();
        $clerk = $this->addMember($company, 'user');
        $type = VoucherType::where('base_type', 'journal')->first();

        $this->actingAs($clerk)->post('/vouchers', [
            'voucher_type_id' => $type->id, 'date' => '2026-07-01', 'action' => 'post',
            'entries' => [
                ['side' => 'Dr', 'account_id' => Account::idFor('bank_charges'), 'amount' => '100'],
                ['side' => 'Cr', 'account_id' => Account::idFor('capital'), 'amount' => '100'],
            ],
        ]);

        // The "user" role has no post permission: the voucher is kept as a draft.
        $voucher = Voucher::firstOrFail();
        $this->assertSame(Voucher::DRAFT, $voucher->status);
        $this->actingAs($clerk)->post("/vouchers/{$voucher->id}/post")->assertForbidden();

        $this->actingAs($owner)->post("/vouchers/{$voucher->id}/post");
        $this->actingAs($clerk)->get("/vouchers/{$voucher->id}/edit")->assertForbidden();
        $this->actingAs($clerk)->post("/vouchers/{$voucher->id}/cancel", ['reason' => 'x'])->assertForbidden();
    }

    public function test_vouchers_of_other_companies_are_hidden(): void
    {
        [$owner] = $this->createCompanyWithOwner(['name' => 'One']);
        $type = VoucherType::where('base_type', 'journal')->first();
        app(\App\Services\Accounting\VoucherService::class)->create([
            'voucher_type_id' => $type->id, 'date' => '2026-07-01',
            'entries' => [
                ['side' => 'Dr', 'account_id' => Account::idFor('bank_charges'), 'amount' => '100'],
                ['side' => 'Cr', 'account_id' => Account::idFor('capital'), 'amount' => '100'],
            ],
        ], $owner);
        $voucher = Voucher::firstOrFail();

        [$intruder] = $this->createCompanyWithOwner(['name' => 'Two']);
        $this->actingAs($intruder)->get("/vouchers/{$voucher->id}")->assertNotFound();
        $this->actingAs($intruder)->get('/vouchers')->assertDontSee('JV/0001');
    }

    public function test_open_bills_endpoint(): void
    {
        [$owner] = $this->createCompanyWithOwner();
        $customer = Account::create([
            'name' => 'Kumar & Sons', 'account_group_id' => AccountGroup::idFor('sundry_debtors'),
            'account_type' => 'party', 'maintain_bill_wise' => true, 'opening_balance' => 0, 'opening_balance_type' => 'Dr',
        ]);
        app(\App\Services\Accounting\VoucherService::class)->create([
            'voucher_type_id' => VoucherType::where('base_type', 'sales')->value('id'), 'date' => '2026-07-01',
            'entries' => [
                ['side' => 'Dr', 'account_id' => $customer->id, 'amount' => '500', 'bills' => [['bill_type' => 'new_ref', 'bill_ref' => 'S-9', 'amount' => '500']]],
                ['side' => 'Cr', 'account_id' => Account::idFor('sales'), 'amount' => '500'],
            ],
        ], $owner);

        $this->actingAs($owner)->getJson("/ledgers/{$customer->id}/open-bills")
            ->assertOk()->assertJson([['ref' => 'S-9', 'pending' => '500.00', 'side' => 'Dr']]);
    }

    public function test_voucher_type_management(): void
    {
        [$owner] = $this->createCompanyWithOwner();

        $this->actingAs($owner)->post('/voucher-types', [
            'name' => 'Cash Payment', 'base_type' => 'payment', 'numbering' => 'auto', 'prefix' => 'CP-',
            'number_width' => 3, 'starting_number' => 1, 'restart_yearly' => '1', 'is_active' => '1',
        ])->assertRedirect(route('voucher-types.index'));

        $this->actingAs($owner)->get('/voucher-types')->assertOk()->assertSee('CP-001');

        $system = VoucherType::where('name', 'Journal')->first();
        $this->actingAs($owner)->put("/voucher-types/{$system->id}", [
            'name' => 'Journal', 'base_type' => 'sales', 'numbering' => 'auto', 'prefix' => 'J-',
            'number_width' => 2, 'starting_number' => 1,
        ])->assertRedirect();
        $this->assertSame('journal', $system->fresh()->base_type);
        $this->assertSame('J-', $system->fresh()->prefix);
    }
}
