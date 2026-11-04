<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsabilityTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $bank;

    protected Account $rent;

    protected Account $repairs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'state' => 'Maharashtra', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->bank = $this->ledger('HDFC Bank', 'BANK_ACCOUNTS');
        $this->rent = $this->ledger('Rent', 'INDIRECT_EXPENSES');
        $this->repairs = $this->ledger('Repairs', 'INDIRECT_EXPENSES');
    }

    protected function ledger(string $name, string $code): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create([
            'name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature,
        ]);
    }

    protected function payment(float $amount): Voucher
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.vouchers.store', $this->company), [
                'type' => 'payment',
                'date' => '2026-06-01',
                'entries' => [
                    ['account_id' => $this->rent->id, 'debit' => $amount],
                    ['account_id' => $this->bank->id, 'credit' => $amount],
                ],
            ])
            ->assertSessionHasNoErrors();

        return $this->company->vouchers()->latest('id')->firstOrFail();
    }

    public function test_inr_uses_indian_grouping(): void
    {
        $this->assertSame('0.00', inr(0));
        $this->assertSame('999.00', inr(999));
        $this->assertSame('1,15,000.00', inr(115000));
        $this->assertSame('12,34,567.50', inr(1234567.5));
        $this->assertSame('-2,950.00', inr(-2950));
        $this->assertSame('1,00,00,000.00', inr(10000000));
        $this->assertSame('1,000.500', inr('1000.5', 3));
    }

    public function test_voucher_can_be_edited_keeping_number_and_type(): void
    {
        $voucher = $this->payment(1000);

        $this->actingAs($this->company->user)
            ->get(route('companies.vouchers.edit', [$this->company, $voucher]))
            ->assertOk()
            ->assertSee('Edit Payment #1');

        $this->actingAs($this->company->user)
            ->put(route('companies.vouchers.update', [$this->company, $voucher]), [
                'type' => 'journal', // ignored
                'date' => '2026-06-02',
                'narration' => 'Corrected',
                'entries' => [
                    ['account_id' => $this->repairs->id, 'debit' => '1200'],
                    ['account_id' => $this->bank->id, 'credit' => '1200'],
                ],
            ])
            ->assertRedirect(route('companies.vouchers.show', [$this->company, $voucher]));

        $voucher->refresh();
        $this->assertSame('payment', $voucher->type);
        $this->assertSame(1, $voucher->number);
        $this->assertSame('1200.00', $voucher->amount);
        $this->assertSame('Corrected', $voucher->narration);
        $this->assertSame([$this->repairs->id, $this->bank->id], $voucher->entries()->pluck('account_id')->all());
    }

    public function test_edit_still_enforces_rules(): void
    {
        $voucher = $this->payment(1000);

        $this->actingAs($this->company->user)
            ->put(route('companies.vouchers.update', [$this->company, $voucher]), [
                'date' => '2026-06-02',
                'entries' => [
                    ['account_id' => $this->rent->id, 'debit' => '1000'],
                    ['account_id' => $this->repairs->id, 'credit' => '1000'],
                ],
            ])
            ->assertSessionHasErrors('entries'); // payment must credit cash/bank

        $this->assertSame($this->bank->id, $voucher->entries()->where('credit', '>', 0)->value('account_id'));
    }

    public function test_edit_keeps_bank_reconciliation_on_unchanged_lines(): void
    {
        $voucher = $this->payment(1000);
        $voucher->entries()->where('account_id', $this->bank->id)->update(['bank_date' => '2026-06-04', 'instrument_no' => '000123']);

        $this->actingAs($this->company->user)
            ->put(route('companies.vouchers.update', [$this->company, $voucher]), [
                'date' => '2026-06-01',
                'narration' => 'Only narration changed',
                'entries' => [
                    ['account_id' => $this->rent->id, 'debit' => '1000'],
                    ['account_id' => $this->bank->id, 'credit' => '1000'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $bankEntry = $voucher->entries()->where('account_id', $this->bank->id)->firstOrFail();
        $this->assertSame('2026-06-04', $bankEntry->bank_date->toDateString());
        $this->assertSame('000123', $bankEntry->instrument_no);
    }

    public function test_gst_invoice_cannot_be_edited_as_plain_voucher(): void
    {
        $sales = $this->ledger('Sales', 'SALES_ACCOUNTS');
        $party = $this->ledger('Customer', 'SUNDRY_DEBTORS');

        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => 'sales',
                'date' => '2026-06-01',
                'party_account_id' => $party->id,
                'lines' => [['account_id' => $sales->id, 'taxable' => 100, 'rate' => 18]],
            ])
            ->assertSessionHasNoErrors();
        $invoice = $this->company->vouchers()->firstOrFail();

        $this->actingAs($this->company->user)
            ->get(route('companies.vouchers.edit', [$this->company, $invoice]))
            ->assertRedirect(route('companies.vouchers.show', [$this->company, $invoice]))
            ->assertSessionHas('error');
    }

    public function test_reports_offer_excel_export(): void
    {
        foreach (['companies.reports.trial-balance', 'companies.gst.gstr1', 'companies.inventory.summary', 'companies.tds.index'] as $route) {
            $this->actingAs($this->company->user)
                ->get(route($route, $this->company))
                ->assertOk()
                ->assertSee('Export to Excel')
                ->assertSee('acme-traders-', false);
        }
    }

    public function test_sidebar_shows_company_menu_inside_a_company(): void
    {
        $this->actingAs($this->company->user)
            ->get(route('companies.reports.index', $this->company))
            ->assertOk()
            ->assertSee(route('companies.vouchers.index', $this->company))
            ->assertSee(route('companies.banking.reconciliation', $this->company))
            ->assertDontSee('href="#accounting"', false);

        $this->actingAs($this->company->user)
            ->get(route('companies.index'))
            ->assertOk()
            ->assertDontSee(route('companies.vouchers.index', $this->company));
    }
}
