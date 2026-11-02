<?php

namespace Tests\Feature\Billing;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Voucher;
use App\Services\Billing\InvoiceService;
use App\Services\Reports\Outstanding;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $customer;
    protected Account $gujaratCustomer;
    protected Account $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();

        $this->customer = $this->party('Mumbai Retail', 'sundry_debtors', 'Maharashtra', 'regular', '27ABCDE1234F1Z5', 30);
        $this->gujaratCustomer = $this->party('Surat Textiles', 'sundry_debtors', 'Gujarat', 'regular', '24ABCDE1234F1Z5');
        $this->supplier = $this->party('Pune Supplies', 'sundry_creditors', 'Maharashtra', 'regular', '27PQRSX1234F1Z5');
    }

    protected function party(string $name, string $group, string $state, string $reg, ?string $gstin = null, ?int $days = null): Account
    {
        $ledger = Account::create([
            'name' => $name, 'account_group_id' => AccountGroup::idFor($group), 'account_type' => 'party',
            'maintain_bill_wise' => true, 'credit_period_days' => $days,
        ]);
        Party::create(['account_id' => $ledger->id, 'state' => $state, 'gst_registration_type' => $reg, 'gstin' => $gstin, 'billing_address' => '1 Main Road']);

        return $ledger->fresh();
    }

    protected function save(string $kind, Account $party, array $items, array $extra = [], string $intent = 'post', ?Invoice $invoice = null, ?string $reason = null): Invoice
    {
        return app(InvoiceService::class)->save($invoice, $kind, array_merge([
            'date' => '2026-09-01',
            'party_account_id' => $party->id,
            'items' => $items,
        ], $extra), $this->owner, $intent, $reason);
    }

    protected function item(string $rate, string $qty = '1', string $gst = '18', ?string $ledgerKey = null): array
    {
        return ['account_id' => Account::idFor($ledgerKey ?? 'sales'), 'description' => 'Widget', 'quantity' => $qty, 'rate' => $rate, 'gst_rate' => $gst, 'hsn_sac' => '8471'];
    }

    /** @return array<string, string> ledger system key or name => "Dr 123.00" */
    protected function lines(Voucher $voucher): array
    {
        return $voucher->entries()->with('account')->get()->mapWithKeys(fn ($e) => [
            $e->account->system_key ?? $e->account->name => $e->side().' '.Money::fromPaise($e->amountPaise()),
        ])->all();
    }

    public function test_intra_state_sales_invoice_posts_a_balanced_voucher(): void
    {
        $invoice = $this->save('sales', $this->customer, [$this->item('1000', '2'), $this->item('333.33', '1', '5')]);

        $this->assertSame('posted', $invoice->status());
        $this->assertSame('INV/0001', $invoice->number());
        $this->assertSame('intra', $invoice->tax_mode);
        // 2,000 @18% => 180 + 180; 333.33 @5% => 8.33 + 8.33 (8.33325 each)
        $this->assertSame('2333.33', $invoice->taxable_total);
        $this->assertSame('188.33', $invoice->cgst_total);
        $this->assertSame('188.33', $invoice->sgst_total);
        $this->assertSame('0.01', $invoice->round_off);
        $this->assertSame('2710.00', $invoice->grand_total);
        $this->assertSame('2026-10-01', $invoice->due_date->toDateString()); // 30 days credit

        $this->assertSame([
            'Mumbai Retail' => 'Dr 2710.00',
            'sales' => 'Cr 2333.33',
            'output_cgst' => 'Cr 188.33',
            'output_sgst' => 'Cr 188.33',
            'round_off' => 'Cr 0.01',
        ], $this->lines($invoice->voucher));

        $bill = $invoice->voucher->entries()->first()->billAllocations()->first();
        $this->assertSame(['new_ref', 'INV/0001', '2026-10-01'], [$bill->bill_type, $bill->bill_ref, $bill->due_date->toDateString()]);
        $this->assertSame(Invoice::class, $invoice->voucher->source_type);
    }

    public function test_inter_state_sale_uses_igst(): void
    {
        $invoice = $this->save('sales', $this->gujaratCustomer, [$this->item('500', '3', '12')]);

        $this->assertSame('inter', $invoice->tax_mode);
        $this->assertSame('180.00', $invoice->igst_total);
        $this->assertSame('0.00', $invoice->cgst_total);
        $this->assertSame(['Surat Textiles' => 'Dr 1680.00', 'sales' => 'Cr 1500.00', 'output_igst' => 'Cr 180.00'], $this->lines($invoice->voucher));
    }

    public function test_place_of_supply_overrides_party_state_for_sales(): void
    {
        $invoice = $this->save('sales', $this->customer, [$this->item('100')], ['place_of_supply' => 'Karnataka']);

        $this->assertSame('inter', $invoice->tax_mode);
    }

    public function test_purchase_uses_supplier_bill_number_and_input_tax(): void
    {
        $bill = $this->save('purchase', $this->supplier, [$this->item('1000', '1', '18', 'purchase')], ['reference' => 'PS-778']);

        $this->assertSame([
            'Pune Supplies' => 'Cr 1180.00',
            'purchase' => 'Dr 1000.00',
            'input_cgst' => 'Dr 90.00',
            'input_sgst' => 'Dr 90.00',
        ], $this->lines($bill->voucher));
        $this->assertSame('PS-778', $bill->voucher->entries()->first()->billAllocations()->value('bill_ref'));

        // The same supplier bill cannot be entered twice.
        try {
            $this->save('purchase', $this->supplier, [$this->item('50', '1', '18', 'purchase')], ['reference' => 'ps-778']);
            $this->fail('Duplicate supplier bill accepted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already exists', implode(' ', $e->errors()['invoice']));
        }
        $this->assertSame(1, Invoice::ofKind('purchase')->count()); // rolled back
    }

    public function test_no_gst_on_purchases_from_unregistered_suppliers(): void
    {
        $local = $this->party('Local Vendor', 'sundry_creditors', 'Maharashtra', 'unregistered');
        $bill = $this->save('purchase', $local, [$this->item('999.50', '1', '18', 'purchase')]);

        $this->assertSame('none', $bill->tax_mode);
        $this->assertSame('0.00', $bill->items->first()->gst_rate);
        $this->assertSame(['Local Vendor' => 'Cr 1000.00', 'purchase' => 'Dr 999.50', 'round_off' => 'Dr 0.50'], $this->lines($bill->voucher));
    }

    public function test_item_ledger_must_match_the_document(): void
    {
        $this->expectException(ValidationException::class);
        $this->save('sales', $this->customer, [$this->item('100', '1', '18', 'purchase')]);
    }

    public function test_party_must_be_a_customer_for_sales(): void
    {
        $this->expectException(ValidationException::class);
        $this->save('sales', $this->supplier, [$this->item('100')]);
    }

    public function test_cash_sale_without_bill_wise(): void
    {
        $invoice = $this->save('sales', Account::where('system_key', 'cash')->first(), [$this->item('100', '1', '0')]);

        $this->assertSame(['cash' => 'Dr 100.00', 'sales' => 'Cr 100.00'], $this->lines($invoice->voucher));
    }

    public function test_draft_has_no_voucher_until_posted(): void
    {
        $draft = $this->save('sales', $this->customer, [$this->item('100')], [], 'draft');

        $this->assertNull($draft->voucher_id);
        $this->assertSame('draft', $draft->status());
        $this->assertSame(0, Voucher::count());

        $posted = app(InvoiceService::class)->post($draft, $this->owner);
        $this->assertSame('posted', $posted->status());
        $this->assertSame('INV/0001', $posted->number());
    }

    public function test_alteration_keeps_number_and_records_a_revision(): void
    {
        $invoice = $this->save('sales', $this->customer, [$this->item('100')]);
        $altered = $this->save('sales', $this->customer, [$this->item('200')], [], 'post', $invoice, 'Wrong rate');

        $this->assertSame($invoice->voucher_id, $altered->voucher_id);
        $this->assertSame('INV/0001', $altered->number());
        $this->assertSame('236.00', $altered->grand_total);
        $this->assertSame(1, $altered->voucher->revisions()->count());
        $this->assertSame('Dr 236.00', $this->lines($altered->voucher)['Mumbai Retail']);
        $this->assertSame('INV/0001', $altered->voucher->entries()->first()->billAllocations()->value('bill_ref'));
    }

    public function test_credit_note_settles_the_invoice_and_cannot_exceed_it(): void
    {
        $invoice = $this->save('sales', $this->customer, [$this->item('1000')]); // 1,180
        $note = $this->save('credit_note', $this->customer, [$this->item('100')], ['original_invoice_id' => $invoice->id]); // 118

        $this->assertSame(['Mumbai Retail' => 'Cr 118.00', 'sales' => 'Dr 100.00', 'output_cgst' => 'Dr 9.00', 'output_sgst' => 'Dr 9.00'], $this->lines($note->voucher));
        $this->assertSame('CN/0001', $note->number());

        $bills = app(Outstanding::class)->bills($this->customer, '2026-09-30');
        $this->assertSame(106200, $bills->firstWhere('bill_ref', 'INV/0001')->amount);

        // The invoice cannot be cancelled while the note refers to it.
        try {
            app(InvoiceService::class)->cancel($invoice->fresh(), $this->owner, 'test');
            $this->fail('Cancelled an invoice with a credit note.');
        } catch (ValidationException) {
        }

        $this->expectException(ValidationException::class);
        $this->save('credit_note', $this->customer, [$this->item('5000')], ['original_invoice_id' => $invoice->id]);
    }

    public function test_cancel_removes_the_invoice_from_balances(): void
    {
        $invoice = $this->save('sales', $this->customer, [$this->item('100')]);
        app(InvoiceService::class)->cancel($invoice, $this->owner, 'Raised in error');

        $this->assertSame('cancelled', $invoice->fresh()->status());
        $this->assertSame(0, app(Outstanding::class)->balances([$this->customer->id])[$this->customer->id]);
    }

    public function test_outstanding_ageing(): void
    {
        // Due 1 Oct (30 days credit); as on 15 Nov it is 45 days overdue.
        $this->save('sales', $this->customer, [$this->item('1000', '1', '0')]);
        $this->save('sales', $this->customer, [$this->item('500', '1', '0')], ['date' => '2026-10-20']); // due 19 Nov
        Account::whereKey($this->customer->id)->update(['opening_balance' => '250', 'opening_balance_type' => 'Dr']);

        $row = app(Outstanding::class)->summary('receivable', '2026-11-15')->first();

        $this->assertSame(175000, $row->balance);
        $this->assertSame(100000, $row->{'31_60'});
        $this->assertSame(50000, $row->not_due);
        $this->assertSame(25000, $row->on_account);
        $this->assertSame(100000, $row->overdue);
    }

    public function test_screens_render(): void
    {
        $invoice = $this->save('sales', $this->customer, [$this->item('1000')]);
        $this->actingAs($this->owner);

        $this->get(route('invoices.index', ['kind' => 'sales']))->assertOk()->assertSee('INV/0001');
        $this->get(route('invoices.create', ['kind' => 'sales']))->assertOk()->assertSee('Mumbai Retail');
        $this->get(route('invoices.create', ['kind' => 'credit_note', 'from' => $invoice->id]))->assertOk();
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('1,180.00');
        $this->get(route('invoices.edit', $invoice))->assertOk();
        $this->get(route('invoices.print', $invoice))->assertOk()->assertSee('Tax Invoice')->assertSee('Indian Rupees One Thousand One Hundred Eighty Only');
        $this->get(route('reports.outstanding', ['type' => 'receivable']))->assertOk()->assertSee('Mumbai Retail');
        $this->get(route('reports.outstanding', ['type' => 'receivable', 'export' => 'csv']))->assertOk();
        $this->get(route('reports.outstanding.party', $this->customer))->assertOk()->assertSee('INV/0001');
        $this->get(route('invoices.index', ['kind' => 'purchase']))->assertOk();
        $this->get(route('invoices.create', ['kind' => 'debit_note']))->assertOk();
    }

    public function test_invoice_entry_over_http(): void
    {
        $this->actingAs($this->owner)->post(route('invoices.store'), [
            'kind' => 'sales',
            'date' => '2026-09-05',
            'party_account_id' => $this->gujaratCustomer->id,
            'action' => 'post',
            'items' => [
                ['account_id' => Account::idFor('sales'), 'description' => 'Cloth', 'quantity' => '10', 'rate' => '50', 'gst_rate' => '5'],
                ['account_id' => '', 'description' => '', 'rate' => ''],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $invoice = Invoice::firstOrFail();
        $this->assertSame('525.00', $invoice->grand_total);
        $this->assertCount(1, $invoice->items);

        // Posted alteration needs a reason.
        $this->put(route('invoices.update', $invoice), ['date' => '2026-09-05', 'party_account_id' => $this->gujaratCustomer->id, 'items' => [['account_id' => Account::idFor('sales'), 'quantity' => '1', 'rate' => '1', 'gst_rate' => '0']]])
            ->assertSessionHasErrors('alteration_reason');

        // Posted documents are not deleted.
        $this->delete(route('invoices.destroy', $invoice))->assertSessionHasErrors('invoice');
        $this->assertModelExists($invoice);
    }

    public function test_permissions_follow_the_module(): void
    {
        $invoice = $this->save('sales', $this->customer, [$this->item('100')]);
        $buyer = $this->addMember($this->company, 'purchases');
        $this->actingAs($buyer);

        $this->get(route('invoices.index', ['kind' => 'purchase']))->assertOk();
        $this->get(route('invoices.index', ['kind' => 'sales']))->assertForbidden();
        $this->get(route('invoices.show', $invoice))->assertForbidden();
        $this->get(route('customers.index'))->assertForbidden();
        $this->get(route('suppliers.index'))->assertOk();
    }

    public function test_other_companies_invoices_are_not_reachable(): void
    {
        $invoice = $this->save('sales', $this->customer, [$this->item('100')]);
        [$other, $otherCompany] = $this->createCompanyWithOwner(['name' => 'Other Co']);

        $this->actingAs($other)->get(route('invoices.show', $invoice->id))->assertNotFound();
        $this->actingAs($other)->get(route('customers.show', $this->customer->id))->assertNotFound();
    }

    public function test_voucher_generated_by_an_invoice_cannot_be_edited_directly(): void
    {
        $invoice = $this->save('sales', $this->customer, [$this->item('100')]);

        $this->actingAs($this->owner)->get(route('vouchers.edit', $invoice->voucher_id))->assertForbidden();
    }
}
