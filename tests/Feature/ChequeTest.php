<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use App\Support\CompanyBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChequeTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $bank;

    protected Account $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Cheque Co', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);
        $bankGroup = $this->company->accountGroups()->where('code', 'BANK_ACCOUNTS')->firstOrFail();
        $this->bank = $this->company->accounts()->create(['name' => 'ICICI Bank', 'account_group_id' => $bankGroup->id, 'account_type' => $bankGroup->nature]);
        $debtors = $this->company->accountGroups()->where('code', 'SUNDRY_DEBTORS')->firstOrFail();
        $this->customer = $this->company->accounts()->create(['name' => 'Ravi Traders', 'account_group_id' => $debtors->id, 'account_type' => $debtors->nature]);
    }

    /** A receipt of ₹5,000 by cheque 000123. */
    protected function receiveCheque(?string $bankDate = null): VoucherEntry
    {
        $voucher = $this->company->vouchers()->create(['type' => 'receipt', 'number' => 1, 'date' => '2026-06-10', 'amount' => 5000]);
        $entry = $voucher->entries()->create(['account_id' => $this->bank->id, 'debit' => 5000, 'credit' => 0, 'instrument_no' => '000123', 'bank_date' => $bankDate]);
        $voucher->entries()->create(['account_id' => $this->customer->id, 'debit' => 0, 'credit' => 5000]);

        return $entry;
    }

    public function test_register_lists_cheques_with_status(): void
    {
        $this->receiveCheque();
        $user = $this->company->user;

        $this->actingAs($user)->get(route('companies.cheques.index', $this->company))
            ->assertOk()->assertSee('000123')->assertSee('Ravi Traders')->assertSee('Pending');
        $this->actingAs($user)->get(route('companies.cheques.index', [$this->company, 'status' => 'cleared']))
            ->assertOk()->assertDontSee('000123');
        $this->actingAs($user)->get(route('companies.cheques.index', [$this->company, 'direction' => 'received']))
            ->assertOk()->assertSee('000123');
        $this->actingAs($user)->get(route('companies.cheques.index', [$this->company, 'direction' => 'issued']))
            ->assertOk()->assertDontSee('000123');
    }

    public function test_bounce_reverses_the_voucher(): void
    {
        $entry = $this->receiveCheque();

        $this->actingAs($this->company->user)
            ->post(route('companies.cheques.bounce', [$this->company, $entry]), ['date' => '2026-06-15'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Cheque 000123 marked bounced. Journal #1 reverses it.');

        $journal = Voucher::findOrFail($entry->fresh()->bounced_voucher_id);
        $this->assertSame('journal', $journal->type);
        $this->assertSame(0.0, (float) $this->bank->entries()->sum('debit') - (float) $this->bank->entries()->sum('credit'));
        $this->assertSame(0.0, (float) $this->customer->entries()->sum('debit') - (float) $this->customer->entries()->sum('credit'));

        // Twice is refused.
        $this->actingAs($this->company->user)
            ->post(route('companies.cheques.bounce', [$this->company, $entry]), ['date' => '2026-06-15'])
            ->assertSessionHasErrors('date');
        $this->actingAs($this->company->user)->get(route('companies.cheques.index', [$this->company, 'status' => 'bounced']))
            ->assertSee('000123');
    }

    public function test_cleared_cheque_or_early_date_cannot_bounce(): void
    {
        $cleared = $this->receiveCheque('2026-06-12');
        $this->actingAs($this->company->user)
            ->post(route('companies.cheques.bounce', [$this->company, $cleared]), ['date' => '2026-06-15'])
            ->assertSessionHasErrors('date');

        $cleared->update(['bank_date' => null]);
        $this->actingAs($this->company->user)
            ->post(route('companies.cheques.bounce', [$this->company, $cleared]), ['date' => '2026-06-01'])
            ->assertSessionHasErrors('date');
    }

    public function test_other_company_entry_is_not_found_and_backup_keeps_link(): void
    {
        $entry = $this->receiveCheque();
        $other = User::factory()->create()->companies()->create(['name' => 'Other', 'financial_year' => '2026-27', 'currency' => 'INR']);
        $this->actingAs($other->user)
            ->post(route('companies.cheques.bounce', [$other, $entry]), ['date' => '2026-06-15'])
            ->assertNotFound();

        $this->actingAs($this->company->user)->post(route('companies.cheques.bounce', [$this->company, $entry]), ['date' => '2026-06-15']);
        $copy = CompanyBackup::restore(CompanyBackup::export($this->company), $this->company->user, 'Copy');
        $bounced = VoucherEntry::whereIn('voucher_id', $copy->vouchers()->pluck('id'))->whereNotNull('bounced_voucher_id')->firstOrFail();
        $this->assertSame('journal', Voucher::findOrFail($bounced->bounced_voucher_id)->type);
        $this->assertSame((int) $copy->id, (int) Voucher::findOrFail($bounced->bounced_voucher_id)->company_id);

        $this->actingAs($this->company->user)->delete(route('companies.destroy', $this->company))->assertRedirect();
        $this->assertModelMissing($this->company);
    }
}
