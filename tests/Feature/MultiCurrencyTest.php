<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US customer kept in USD.
 *   Export sale: $1,000 @ 83.00 = ₹83,000
 *   Receipt:      $600 @ 84.00 = ₹50,400
 *   Left: $400 owed, ₹32,600 in books (book rate 81.50)
 *   Revalue at 85.00: $400 = ₹34,000 → gain ₹1,400
 */
class MultiCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $customer;

    protected Account $sales;

    protected Account $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Exports', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->actingAs($this->company->user)
            ->post(route('companies.currencies.store', $this->company), ['code' => 'usd'])
            ->assertSessionHasNoErrors();

        $group = fn (string $code) => $this->company->accountGroups()->where('code', $code)->value('id');
        $this->sales = $this->company->accounts()->create(['name' => 'Export Sales', 'account_group_id' => $group('SALES_ACCOUNTS'), 'account_type' => 'income']);
        $this->bank = $this->company->accounts()->create(['name' => 'HDFC Bank', 'account_group_id' => $group('BANK_ACCOUNTS'), 'account_type' => 'assets']);

        $this->actingAs($this->company->user)
            ->post(route('companies.accounts.store', $this->company), [
                'name' => 'Acme Inc (USA)', 'account_group_id' => $group('SUNDRY_DEBTORS'),
                'opening_balance_type' => 'dr', 'currency_code' => 'USD', 'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();
        $this->customer = $this->company->accounts()->where('currency_code', 'USD')->firstOrFail();
    }

    protected function voucher(string $type, string $date, array $entries)
    {
        return $this->actingAs($this->company->user)
            ->post(route('companies.vouchers.store', $this->company), ['type' => $type, 'date' => $date, 'entries' => $entries]);
    }

    protected function postSaleAndReceipt(): void
    {
        $this->voucher('journal', '2026-06-01', [
            ['account_id' => $this->customer->id, 'side' => 'dr', 'foreign_amount' => 1000, 'exchange_rate' => 83],
            ['account_id' => $this->sales->id, 'credit' => 83000],
        ])->assertSessionHasNoErrors();

        $this->voucher('receipt', '2026-07-01', [
            ['account_id' => $this->bank->id, 'debit' => 50400],
            ['account_id' => $this->customer->id, 'side' => 'cr', 'foreign_amount' => 600, 'exchange_rate' => 84],
        ])->assertSessionHasNoErrors();
    }

    public function test_currency_added_with_known_symbol(): void
    {
        $currency = $this->company->currencies()->firstOrFail();
        $this->assertSame(['USD', '$', 'US Dollar'], [$currency->code, $currency->symbol, $currency->name]);

        $this->actingAs($this->company->user)
            ->post(route('companies.currencies.store', $this->company), ['code' => 'INR'])
            ->assertSessionHasErrors('code');
    }

    public function test_rupee_amount_is_foreign_amount_times_rate(): void
    {
        $this->postSaleAndReceipt();

        $sale = $this->customer->entries()->where('debit', '>', 0)->firstOrFail();
        $this->assertSame(['83000.00', 'USD', '1000.00', '83.000000'], [$sale->debit, $sale->currency_code, $sale->foreign_amount, $sale->exchange_rate]);

        $receipt = $this->customer->entries()->where('credit', '>', 0)->firstOrFail();
        $this->assertSame('50400.00', $receipt->credit);
    }

    public function test_foreign_ledger_row_needs_amount_and_rate(): void
    {
        $this->voucher('journal', '2026-06-01', [
            ['account_id' => $this->customer->id, 'debit' => 83000],
            ['account_id' => $this->sales->id, 'credit' => 83000],
        ])->assertSessionHasErrors('entries.0.foreign_amount');
    }

    public function test_ledger_statement_shows_usd(): void
    {
        $this->postSaleAndReceipt();

        $response = $this->actingAs($this->company->user)
            ->get(route('companies.reports.ledger', [$this->company, 'account' => $this->customer->id]))
            ->assertOk()
            ->assertSee('USD 400.00');

        $rows = $response->viewData('statement')['rows'];
        $this->assertSame([1000.0, -600.0], $rows->pluck('foreign')->all());
    }

    public function test_revaluation_preview_and_post(): void
    {
        $this->postSaleAndReceipt();

        $response = $this->actingAs($this->company->user)
            ->get(route('companies.forex.revaluation', [$this->company, 'to' => '2026-09-30', 'rates' => ['USD' => 85]]))
            ->assertOk()
            ->assertSee('Post revaluation journal');

        $row = $response->viewData('rows')->first();
        $this->assertSame([400.0, 32600.0, 81.5, 34000.0, 1400.0], [$row->foreign, $row->rupees, $row->book_rate, $row->revalued, $row->difference]);

        $this->actingAs($this->company->user)
            ->post(route('companies.forex.post', $this->company), ['to' => '2026-09-30', 'rates' => ['USD' => 85]])
            ->assertRedirect()
            ->assertSessionHas('success', 'Exchange gain of 1,400.00 posted.');

        $gainLedger = $this->company->accounts()->where('code', 'FOREX_GAIN_LOSS')->firstOrFail();
        $this->assertSame('1400.00', $gainLedger->entries()->value('credit'));

        // After posting, the books match the closing rate and the dollar balance is unchanged.
        $after = $this->actingAs($this->company->user)
            ->get(route('companies.forex.revaluation', [$this->company, 'to' => '2026-09-30', 'rates' => ['USD' => 85]]))
            ->viewData('rows')->first();
        $this->assertSame([400.0, 34000.0, 0.0], [$after->foreign, $after->rupees, $after->difference]);

        // Gain shows in P&L.
        $pl = $this->actingAs($this->company->user)->get(route('companies.reports.profit-and-loss', $this->company));
        $this->assertSame(84400.0, $pl->viewData('netProfit'));
    }

    public function test_currency_cannot_change_once_ledger_has_vouchers(): void
    {
        $this->postSaleAndReceipt();

        $this->actingAs($this->company->user)
            ->put(route('companies.accounts.update', [$this->company, $this->customer]), [
                'name' => 'Acme Inc (USA)', 'account_group_id' => $this->customer->account_group_id,
                'opening_balance_type' => 'dr', 'currency_code' => '', 'is_active' => '1',
            ])
            ->assertSessionHasErrors('currency_code');

        // And the currency can't be removed while used.
        $usd = $this->company->currencies()->firstOrFail();
        $this->actingAs($this->company->user)
            ->delete(route('companies.currencies.destroy', [$this->company, $usd]))
            ->assertSessionHas('error');
    }

    public function test_voucher_form_and_edit_keep_foreign_fields(): void
    {
        $this->postSaleAndReceipt();
        $sale = $this->company->vouchers()->where('type', 'journal')->firstOrFail();

        $this->actingAs($this->company->user)
            ->get(route('companies.vouchers.edit', [$this->company, $sale]))
            ->assertOk()
            ->assertSee('name="entries[0][foreign_amount]" value="1000"', false);
    }
}
