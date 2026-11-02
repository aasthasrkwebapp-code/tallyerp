<?php

namespace Tests\Feature\Banking;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\PositivePayBatch;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Models\VoucherType;
use App\Services\Accounting\VoucherService;
use App\Services\Banking\PositivePay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PositivePayTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $bank;
    protected Account $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->company->update(['settings' => ['allow_future_dated_vouchers' => true]]);
        $this->bank = Account::create(['name' => 'HDFC Current', 'account_group_id' => AccountGroup::idFor('bank_accounts'), 'account_type' => 'bank',
            'opening_balance' => 1000000, 'bank_account_number' => '50200012345678', 'ifsc' => 'HDFC0000123']);
        $this->supplier = Account::create(['name' => 'Delhi Supplies', 'account_group_id' => AccountGroup::idFor('sundry_creditors'), 'account_type' => 'party']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function cheque(string $number, string $amount, string $date = '2026-10-10', ?Account $bank = null): Voucher
    {
        $bank ??= $this->bank;

        return app(VoucherService::class)->create([
            'voucher_type_id' => VoucherType::where('base_type', 'payment')->value('id'), 'date' => $date,
            'instrument_type' => 'cheque', 'instrument_number' => $number,
            'entries' => [['account_id' => $this->supplier->id, 'side' => 'Dr', 'amount' => $amount], ['account_id' => $bank->id, 'side' => 'Cr', 'amount' => $amount]],
        ], $this->owner);
    }

    public function test_due_cheques_file_and_cancellations(): void
    {
        $big = $this->cheque('101', '75000');
        $this->cheque('102', '49999.99'); // below the threshold
        $postDated = $this->cheque('103', '200000', '2026-11-05');
        $cleared = $this->cheque('104', '60000');
        VoucherEntry::where('voucher_id', $cleared->id)->where('account_id', $this->bank->id)->update(['bank_date' => '2026-10-15']);
        $stopped = $this->cheque('105', '90000');
        app(VoucherService::class)->cancel($stopped, $this->owner, 'Wrong party'); // cancelled before reporting: nothing to send

        $pp = app(PositivePay::class);
        $due = $pp->due($this->bank, PositivePay::DEFAULT_THRESHOLD);
        $this->assertSame(['000101', '000103'], $due['issue']->pluck('number')->all());
        $this->assertCount(0, $due['cancel']);
        $this->assertSame(['000101', '000102', '000103'], $pp->due($this->bank, '0')['issue']->pluck('number')->all());

        $batch = $pp->create($this->bank, PositivePay::DEFAULT_THRESHOLD, $this->owner);
        $this->assertSame([2, '275000.00'], [$batch->item_count, $batch->total]);
        $this->assertSame([
            ['50200012345678', 'HDFC0000123', '000101', '10-10-2026', '75000.00', 'Delhi Supplies', 'I'],
            ['50200012345678', 'HDFC0000123', '000103', '05-11-2026', '200000.00', 'Delhi Supplies', 'I'],
        ], $pp->lines($batch)->all());

        // Reported once only; a reported cheque that is cancelled goes out as a cancellation.
        $this->assertCount(0, $pp->due($this->bank, PositivePay::DEFAULT_THRESHOLD)['issue']);
        app(VoucherService::class)->cancel($postDated, $this->owner, 'Stopped payment');
        $this->cheque('106', '55000');
        $due = $pp->due($this->bank, PositivePay::DEFAULT_THRESHOLD);
        $this->assertSame(['000106'], $due['issue']->pluck('number')->all());
        $this->assertSame(['000103'], $due['cancel']->pluck('number')->all());
        $second = $pp->create($this->bank, PositivePay::DEFAULT_THRESHOLD, $this->owner);
        $this->assertSame(['I', 'C'], $pp->lines($second)->pluck(6)->all());
        $this->assertSame('55000.00', $second->total);

        // The first file is unchanged.
        $this->assertCount(2, $pp->lines($batch->fresh()));
        $this->assertSame(['issue' => 0, 'cancel' => 0], array_map(fn ($c) => $c->count(), $pp->due($this->bank, PositivePay::DEFAULT_THRESHOLD)));
        $this->assertSame(75000, (int) $big->total);
    }

    public function test_refusals(): void
    {
        $pp = app(PositivePay::class);
        try {
            $pp->create($this->bank, PositivePay::DEFAULT_THRESHOLD, $this->owner);
            $this->fail('Made an empty file');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no cheques to report', $e->errors()['bank'][0]);
        }
        $other = Account::create(['name' => 'SBI', 'account_group_id' => AccountGroup::idFor('bank_accounts'), 'account_type' => 'bank', 'opening_balance' => 100000]);
        $this->cheque('201', '80000', '2026-10-10', $other);
        try {
            $pp->create($other, PositivePay::DEFAULT_THRESHOLD, $this->owner);
            $this->fail('Made a file without an account number');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('account number of SBI', $e->errors()['bank'][0]);
        }
    }

    public function test_screens_downloads_and_permissions(): void
    {
        $this->cheque('101', '75000');
        $this->actingAs($this->owner)->get(route('positive-pay.index'))->assertOk()
            ->assertSee('Due to report: 1 cheque(s), 0 cancellation(s)')->assertSee('000101')->assertSee('Make positive pay file');
        $this->post(route('positive-pay.store'), ['bank' => $this->bank->id, 'threshold' => '50000'])->assertRedirect();
        $batch = PositivePayBatch::firstOrFail();
        $this->get(route('positive-pay.show', $batch))->assertOk()->assertSee('Positive pay file #'.$batch->id)->assertSee('Delhi Supplies');
        $csv = $this->get(route('positive-pay.show', [$batch, 'export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('"Account Number",IFSC,"Cheque Number","Cheque Date",Amount,"Payee Name",Action', $csv);
        $this->assertStringContainsString('50200012345678,HDFC0000123,000101,10-10-2026,75000.00,"Delhi Supplies",I', $csv);
        $this->get(route('positive-pay.index'))->assertSee('Nothing to report')->assertSee('#'.$batch->id);
        $this->post(route('positive-pay.store'), ['bank' => $this->bank->id])->assertSessionHasErrors('bank');

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('positive-pay.show', $batch))->assertOk();
        $this->actingAs($viewer)->get(route('positive-pay.index'))->assertOk()->assertDontSee('Make positive pay file');
        $this->actingAs($viewer)->post(route('positive-pay.store'), ['bank' => $this->bank->id])->assertForbidden();
    }
}
