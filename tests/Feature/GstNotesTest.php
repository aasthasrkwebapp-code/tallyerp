<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Maharashtra company, local party, 18% GST, item Laptop (opening 10 Nos).
 *   Sale 5 @ 2,000 = 10,000 · Credit note 1 @ 2,000 = 2,000
 *   Purchase 3 @ 1,500 = 4,500 · Debit note 1 @ 1,500 = 1,500
 */
class GstNotesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Account $sales;

    protected Account $purchases;

    protected Account $party;

    protected StockItem $laptop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = User::factory()->create()->companies()->create([
            'name' => 'Acme Traders', 'gstin' => '27AAAAA0000A1Z5', 'financial_year' => '2026-27', 'currency' => 'INR',
        ]);

        $this->sales = $this->ledger('Sales', 'SALES_ACCOUNTS');
        $this->purchases = $this->ledger('Purchases', 'PURCHASE_ACCOUNTS');
        $this->party = $this->ledger('Pune Stores', 'SUNDRY_DEBTORS', ['gstin' => '27BBBBB1111B1Z5', 'state_code' => '27']);
        // Matches the opening stock below so the books open balanced.
        $this->ledger('Capital', 'CAPITAL_ACCOUNT', ['opening_balance' => 10000, 'opening_balance_type' => 'cr']);

        $this->laptop = $this->company->stockItems()->create([
            'name' => 'Laptop', 'unit_id' => $this->company->units()->where('symbol', 'Nos')->value('id'),
            'hsn_sac' => '8471', 'gst_rate' => 18, 'opening_quantity' => 10, 'opening_rate' => 1000,
        ]);

        $this->post_('sales', $this->sales, 5, 2000, '2026-07-01');
        $this->post_('credit_note', $this->sales, 1, 2000, '2026-07-05', 'INV-1');
        $this->post_('purchase', $this->purchases, 3, 1500, '2026-07-08');
        $this->post_('debit_note', $this->purchases, 1, 1500, '2026-07-12', 'PUR-1');
    }

    protected function ledger(string $name, string $code, array $extra = []): Account
    {
        $group = $this->company->accountGroups()->where('code', $code)->firstOrFail();

        return $this->company->accounts()->create($extra + [
            'name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature,
        ]);
    }

    protected function post_(string $type, Account $ledger, float $qty, float $rate, string $date, ?string $reference = null): void
    {
        $this->actingAs($this->company->user)
            ->post(route('companies.gst-invoices.store', $this->company), [
                'type' => $type,
                'date' => $date,
                'reference' => $reference,
                'party_account_id' => $this->party->id,
                'lines' => [[
                    'account_id' => $ledger->id, 'stock_item_id' => $this->laptop->id,
                    'quantity' => $qty, 'item_rate' => $rate, 'hsn_sac' => '8471', 'rate' => 18,
                ]],
            ])
            ->assertSessionHasNoErrors();
    }

    protected function balance(string $code): float
    {
        $account = $this->company->accounts()->where('code', $code)->firstOrFail();

        return round((float) $account->entries()->sum('debit') - (float) $account->entries()->sum('credit'), 2);
    }

    public function test_credit_note_reverses_a_sale(): void
    {
        $note = $this->company->vouchers()->where('type', 'credit_note')->firstOrFail();

        $this->assertSame('2360.00', $note->entries()->where('account_id', $this->party->id)->value('credit'));
        $this->assertSame('2000.00', $note->entries()->where('account_id', $this->sales->id)->value('debit'));

        // Output tax: 900 on the sale less 180 on the return.
        $this->assertSame(-720.0, $this->balance('GST_OUTPUT_CGST'));
        $this->assertSame(-720.0, $this->balance('GST_OUTPUT_SGST'));
    }

    public function test_debit_note_reverses_a_purchase(): void
    {
        $note = $this->company->vouchers()->where('type', 'debit_note')->firstOrFail();

        $this->assertSame('1770.00', $note->entries()->where('account_id', $this->party->id)->value('debit'));
        $this->assertSame('1500.00', $note->entries()->where('account_id', $this->purchases->id)->value('credit'));

        // Input tax: 405 on the purchase less 135 on the return.
        $this->assertSame(270.0, $this->balance('GST_INPUT_CGST'));
    }

    public function test_stock_moves_with_returns(): void
    {
        $row = $this->actingAs($this->company->user)
            ->get(route('companies.inventory.summary', $this->company))
            ->viewData('rows')->first();

        // 10 − 5 sold + 1 returned + 3 bought − 1 returned to supplier
        $this->assertSame(8.0, $row->closing_qty);
    }

    public function test_gstr3b_nets_notes(): void
    {
        $response = $this->actingAs($this->company->user)
            ->get(route('companies.gst.gstr3b', [$this->company, 'month' => '2026-07']))
            ->assertOk();

        $this->assertSame(8000.0, $response->viewData('outward')->taxable);
        $this->assertSame(720.0, $response->viewData('outward')->cgst);
        $this->assertSame(3000.0, $response->viewData('itc')->taxable);
        $this->assertSame(270.0, $response->viewData('itc')->cgst);
        $this->assertSame(450.0, $response->viewData('payable')->cgst);
    }

    public function test_gstr1_lists_credit_notes_and_nets_hsn(): void
    {
        $response = $this->actingAs($this->company->user)
            ->get(route('companies.gst.gstr1', [$this->company, 'month' => '2026-07']))
            ->assertOk()
            ->assertSee('Credit notes (sales returns)')
            ->assertSee('INV-1');

        $this->assertCount(1, $response->viewData('notes'));
        $this->assertSame(2000.0, $response->viewData('notes')[0]->taxable);
        $this->assertSame(8000.0, $response->viewData('hsn')[0]->taxable);
        $this->assertSame(8000.0, $response->viewData('total')->taxable);
    }

    public function test_note_pages_render(): void
    {
        $note = $this->company->vouchers()->where('type', 'credit_note')->firstOrFail();

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.show', [$this->company, $note]))
            ->assertOk()
            ->assertSee('CREDIT NOTE')
            ->assertSee('Credit Note #1');

        $this->actingAs($this->company->user)
            ->get(route('companies.gst-invoices.create', [$this->company, 'type' => 'debit_note']))
            ->assertOk()
            ->assertSee('Debit Note (Purchase Return)');
    }

    public function test_trial_balance_still_balances(): void
    {
        $tb = $this->actingAs($this->company->user)->get(route('companies.reports.trial-balance', $this->company));

        $this->assertSame($tb->viewData('totalDebit'), $tb->viewData('totalCredit'));
    }

    public function test_all_view_files_are_valid_utf8(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($files as $file) {
            if ($file->isFile()) {
                $this->assertTrue(mb_check_encoding(file_get_contents($file->getPathname()), 'UTF-8'), $file->getPathname().' is not UTF-8');
            }
        }
    }
}
