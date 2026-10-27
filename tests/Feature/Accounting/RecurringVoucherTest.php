<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AuditLog;
use App\Models\BillAllocation;
use App\Models\RecurringVoucher;
use App\Models\RecurringVoucherRun;
use App\Models\Voucher;
use App\Models\VoucherType;
use App\Services\Accounting\RecurringVoucherService;
use App\Services\Accounting\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecurringVoucherTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $rent;
    protected Account $landlord;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15 09:00:00');
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->rent = Account::create(['name' => 'Office Rent', 'account_group_id' => AccountGroup::idFor('indirect_expenses'), 'account_type' => 'general']);
        $this->landlord = Account::create(['name' => 'Landlord', 'account_group_id' => AccountGroup::idFor('sundry_creditors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function journal(): int
    {
        return VoucherType::where('base_type', 'journal')->value('id');
    }

    protected function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Office rent',
            'voucher_type_id' => $this->journal(),
            'frequency' => 'monthly',
            'start_date' => '2026-05-31',
            'mode' => 'post',
            'is_active' => '1',
            'narration' => 'Monthly office rent',
            'entries' => [
                ['side' => 'Dr', 'account_id' => $this->rent->id, 'amount' => '25000'],
                ['side' => 'Cr', 'account_id' => $this->landlord->id, 'amount' => '25000', 'bill_type' => 'new_ref', 'due_days' => '10'],
                ['side' => 'Dr'],
            ],
        ], $extra);
    }

    public function test_create_and_catch_up_monthly_vouchers_keeping_month_end(): void
    {
        $this->actingAs($this->owner)->post(route('recurring-vouchers.store'), $this->payload())->assertRedirect();
        $r = RecurringVoucher::sole();
        $this->assertSame('2026-05-31', $r->next_date->toDateString());
        $this->assertCount(2, $r->lines);
        $this->assertSame('25000.00', (string) $r->total);

        $this->actingAs($this->owner)->post(route('recurring-vouchers.run', $r))->assertRedirect()->assertSessionHas('success', '3 voucher(s) made.');

        $vouchers = Voucher::query()->orderBy('date')->get();
        $this->assertSame(['2026-05-31', '2026-06-30', '2026-07-31'], $vouchers->map(fn ($v) => $v->date->toDateString())->all());
        $this->assertTrue($vouchers->every(fn ($v) => $v->status === Voucher::POSTED && $v->narration === 'Monthly office rent'));
        $this->assertSame('2026-08-31', $r->fresh()->next_date->toDateString());

        // The landlord's bill takes each voucher's own number, due 10 days later.
        $bill = BillAllocation::query()->where('account_id', $this->landlord->id)->orderBy('id')->first();
        $this->assertSame('new_ref', $bill->bill_type);
        $this->assertSame($vouchers[0]->voucher_number, $bill->bill_ref);
        $this->assertSame('2026-06-10', $bill->due_date->toDateString());

        $this->assertSame(3, AuditLog::where('event', 'generated')->count());
        $this->assertSame(3, RecurringVoucherRun::where('status', 'created')->count());
        $this->actingAs($this->owner)->get(route('vouchers.show', $vouchers[1]))->assertOk()->assertSee('Made by the recurring voucher');
        $this->actingAs($this->owner)->get(route('recurring-vouchers.show', $r))->assertOk()->assertSee($vouchers[2]->voucher_number)->assertSee('31 Aug 2026');

        // Running again the same day does nothing.
        $this->assertCount(0, app(RecurringVoucherService::class)->run($r->fresh(), $this->owner));
    }

    public function test_unbalanced_template_is_refused(): void
    {
        $payload = $this->payload();
        $payload['entries'][1]['amount'] = '20000';
        $this->actingAs($this->owner)->post(route('recurring-vouchers.store'), $payload)->assertSessionHasErrors();
        $this->assertSame(0, RecurringVoucher::count());
        $this->assertSame(0, Voucher::count());
    }

    public function test_locked_period_fails_and_retries_without_skipping(): void
    {
        $r = app(RecurringVoucherService::class)->save(null, $this->payload(['start_date' => '2026-04-30']), $this->owner);
        $this->company->financialYearFor('2026-04-30')->periods()->whereDate('start_date', '2026-04-01')->update(['is_locked' => true]);

        $runs = app(RecurringVoucherService::class)->run($r, $this->owner);
        $this->assertCount(1, $runs);
        $this->assertSame('failed', $runs[0]->status);
        $this->assertStringContainsString('locked', $runs[0]->message);
        $this->assertSame('2026-04-30', $r->fresh()->next_date->toDateString());
        $this->assertSame(0, Voucher::count());
        $this->actingAs($this->owner)->get(route('recurring-vouchers.show', $r))->assertSee('could not be made');

        // Moving the next date past the locked month lets it continue.
        $this->actingAs($this->owner)->put(route('recurring-vouchers.update', $r), $this->payload(['start_date' => '2026-04-30', 'next_date' => '2026-05-31']))->assertRedirect();
        $this->assertCount(3, app(RecurringVoucherService::class)->run($r->fresh(), $this->owner));
    }

    public function test_draft_mode_end_date_and_pause(): void
    {
        $service = app(RecurringVoucherService::class);
        $r = $service->save(null, $this->payload(['mode' => 'draft', 'frequency' => 'quarterly', 'start_date' => '2026-04-10', 'end_date' => '2026-08-01']), $this->owner);
        $runs = $service->run($r, $this->owner);
        $this->assertCount(2, $runs); // 10 Apr, 10 Jul; 10 Oct is after the end date
        $this->assertSame([Voucher::DRAFT], Voucher::query()->pluck('status')->unique()->values()->all());
        $this->assertNull($r->fresh()->next_date);

        $paused = $service->save(null, $this->payload(['name' => 'Paused', 'is_active' => false]), $this->owner);
        $this->assertCount(0, $service->run($paused, $this->owner));
    }

    public function test_make_recurring_from_a_voucher_and_the_scheduled_command(): void
    {
        $voucher = app(VoucherService::class)->create([
            'voucher_type_id' => $this->journal(), 'date' => '2026-07-05', 'narration' => 'Generator hire',
            'entries' => [
                ['side' => 'Dr', 'account_id' => $this->rent->id, 'amount' => '1200'],
                ['side' => 'Cr', 'account_id' => $this->landlord->id, 'amount' => '1200', 'bills' => [['bill_type' => 'new_ref', 'bill_ref' => 'GH-1', 'amount' => '1200', 'due_date' => '2026-07-20']]],
            ],
        ], $this->owner);

        $this->actingAs($this->owner)->get(route('vouchers.show', $voucher))->assertSee('Make recurring');
        $this->actingAs($this->owner)->get(route('recurring-vouchers.create', ['voucher' => $voucher->id]))->assertOk()
            ->assertSee('Generator hire')->assertSee('value="2026-08-05"', false)->assertSee('value="15"', false);

        $r = app(RecurringVoucherService::class)->save(null, app(RecurringVoucherService::class)->fromVoucher($voucher->load('type')), $this->owner);
        $this->assertSame('new_ref', $r->lines[1]->bill_type);

        $this->artisan('erp:recurring-vouchers')->assertSuccessful();
        $this->assertSame(2, Voucher::count());
        $this->assertSame('2026-08-05', Voucher::query()->latest('id')->first()->date->toDateString());
    }

    public function test_permissions_and_ledger_in_use(): void
    {
        $r = app(RecurringVoucherService::class)->save(null, $this->payload(), $this->owner);
        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('recurring-vouchers.index'))->assertOk()->assertSee('Office rent');
        $this->actingAs($viewer)->post(route('recurring-vouchers.run', $r))->assertForbidden();
        $this->actingAs($viewer)->delete(route('recurring-vouchers.destroy', $r))->assertForbidden();

        $this->assertTrue($this->rent->hasTransactions());

        $this->actingAs($this->owner)->delete(route('recurring-vouchers.destroy', $r))->assertRedirect(route('recurring-vouchers.index'));
        $this->assertSame(0, RecurringVoucher::count());
    }
}
