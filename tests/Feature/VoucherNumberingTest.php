<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherNumberingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $cash;

    protected Account $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'state' => 'Maharashtra', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->cash = $this->ledger('Cash', 'CASH_IN_HAND');
        $this->sales = $this->ledger('Sales', 'SALES_ACCOUNTS');
    }

    protected function ledger(string $name, string $code): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create(['name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature]);
    }

    protected function receipt(string $date)
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.vouchers.store', $this->company), [
                'type' => 'receipt', 'date' => $date,
                'entries' => [['account_id' => $this->cash->id, 'debit' => 100], ['account_id' => $this->sales->id, 'credit' => 100]],
            ]);
    }

    protected function moveTo(int $year): void
    {
        $this->actingAs($this->company->user)
            ->put(route('companies.financial-year', $this->company), ['year' => $year])
            ->assertSessionHasNoErrors();
        $this->company->refresh();
    }

    public function test_numbers_restart_each_financial_year(): void
    {
        $this->receipt('2026-05-01')->assertSessionHasNoErrors();
        $this->receipt('2027-03-31')->assertSessionHasNoErrors();

        $this->moveTo(2027);
        $this->receipt('2027-04-01')->assertSessionHasNoErrors();
        $this->receipt('2027-04-02')->assertSessionHasNoErrors();

        $numbers = $this->company->vouchers()->orderBy('date')->get()
            ->map(fn ($v) => $v->financialYearLabel().'#'.$v->number)->all();

        $this->assertSame(['2026-27#1', '2026-27#2', '2027-28#1', '2027-28#2'], $numbers);
    }

    public function test_editing_into_another_year_takes_that_years_next_number(): void
    {
        $this->receipt('2026-05-01');
        $old = $this->company->vouchers()->firstOrFail();

        $this->moveTo(2027);
        $this->receipt('2027-04-10');

        $this->actingAs($this->company->user)
            ->put(route('companies.vouchers.update', [$this->company, $old]), [
                'date' => '2027-05-01',
                'entries' => [['account_id' => $this->cash->id, 'debit' => 100], ['account_id' => $this->sales->id, 'credit' => 100]],
            ])
            ->assertSessionHasNoErrors();

        $old->refresh();
        $this->assertSame(2027, (int) $old->fy_start_year);
        $this->assertSame(2, $old->number);
    }

    public function test_gst_invoice_shows_number_with_year(): void
    {
        $party = $this->ledger('Customer', 'SUNDRY_DEBTORS');

        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => 'sales', 'date' => '2026-06-01', 'party_account_id' => $party->id,
                'lines' => [['account_id' => $this->sales->id, 'taxable' => 1000, 'rate' => 18]],
            ])
            ->assertSessionHasNoErrors();

        $invoice = $this->company->vouchers()->firstOrFail();

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.show', [$this->company, $invoice]))
            ->assertOk()
            ->assertSee('No. 1/2026-27');
    }
}
