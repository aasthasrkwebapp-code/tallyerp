<?php

namespace Tests\Feature\Data;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\StockItem;
use App\Models\Unit;
use App\Services\Data\ImportService;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class InvoiceImportTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Account $customer;
    protected Account $supplier;
    protected StockItem $rod;

    protected const HEAD = ['Invoice', 'Type', 'Date', 'Party', 'Reference', 'Due Date', 'Item', 'Ledger', 'Description', 'Quantity', 'Unit', 'Rate', 'Discount %', 'GST Rate', 'HSN/SAC', 'Godown', 'Batch', 'Narration'];
    protected const PHEAD = ['Invoice', 'Type', 'Date', 'Party', 'Supplier Bill No', 'Due Date', 'Item', 'Ledger', 'Description', 'Quantity', 'Unit', 'Rate', 'Discount %', 'GST Rate', 'HSN/SAC', 'Godown', 'Batch', 'Narration'];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner(['state' => 'Maharashtra']);
        $this->customer = Account::create(['name' => 'Mumbai Retail', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party', 'maintain_bill_wise' => true, 'credit_period_days' => 30]);
        Party::create(['account_id' => $this->customer->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'regular', 'gstin' => '27ABCDE1234F1Z5']);
        $this->supplier = Account::create(['name' => 'Surat Steel', 'account_group_id' => AccountGroup::idFor('sundry_creditors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $this->supplier->id, 'state' => 'Gujarat', 'gst_registration_type' => 'regular', 'gstin' => '24ABCDE1234F1Z9']);
        $this->rod = StockItem::create(['name' => 'Steel Rod 12mm', 'code' => 'SR12', 'unit_id' => Unit::where('symbol', 'Nos')->value('id'), 'hsn_sac' => '7214', 'gst_rate' => '18']);
    }

    protected function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'inv').'.csv';
        $h = fopen($path, 'w');
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        fclose($h);

        return $path;
    }

    protected function import(string $type, array $rows, bool $commit = true, bool $skip = false): array
    {
        $service = app(ImportService::class);
        $path = $this->csv($rows);

        return $service->run($type, $service->read($path, 'csv', $type), $this->owner, $commit, $skip);
    }

    protected function onHand(): int
    {
        return (int) app(StockLedger::class)->position('2027-03-31')[$this->rod->id]->quantity;
    }

    protected function row(array $values): array
    {
        $row = $values + array_fill(0, 18, '');
        ksort($row);

        return array_values($row);
    }

    public function test_purchase_bills_and_sales_invoices_post_with_gst_and_stock(): void
    {
        // Two purchase lines from Gujarat (IGST), one with a service ledger line.
        $result = $this->import('purchase_invoices', [self::PHEAD,
            $this->row([0 => 'B1', 2 => '2026-04-03', 3 => 'Surat Steel', 4 => 'SS/101', 6 => 'SR12', 9 => '50', 11 => '380', 16 => '']),
            $this->row([0 => 'B1', 6 => 'Purchase', 8 => 'Freight', 9 => '1', 11 => '500', 13 => '5']),
        ]);
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['created']);
        $bill = Invoice::ofKind('purchase')->with('items')->firstOrFail();
        $this->assertSame('posted', $bill->status());
        $this->assertSame('inter', $bill->tax_mode);
        $this->assertSame('SS/101', $bill->reference);
        $this->assertSame('22945.00', $bill->grand_total); // 19000 + 18% + 500 + 5%
        $this->assertSame('Freight', $bill->items[1]->description);
        $this->assertSame(50000, $this->onHand());

        // Sales: two invoices in one file, a credit note, and the due date from credit days.
        $result = $this->import('sales_invoices', [self::HEAD,
            $this->row([0 => '1', 1 => 'sales', 2 => '05-04-2026', 3 => 'mumbai retail', 4 => 'PO-77', 6 => 'Steel Rod 12mm', 9 => '10', 11 => '450', 12 => '10', 17 => 'Opening import']),
            $this->row([0 => '2', 2 => '2026-04-06', 3 => 'Mumbai Retail', 6 => 'Steel Rod 12mm', 9 => '5', 11 => '450']),
            $this->row([0 => '2', 6 => 'Sales', 8 => 'Cutting charges', 9 => '1', 11 => '200', 13 => '18', 14 => '998898']),
            $this->row([0 => 'R1', 1 => 'credit note', 2 => '2026-04-07', 3 => 'Mumbai Retail', 6 => 'SR12', 9 => '1', 11 => '450']),
        ]);
        $this->assertSame([], $result['errors']);
        $this->assertSame(3, $result['created']);
        $first = Invoice::ofKind('sales')->where('reference', 'PO-77')->firstOrFail();
        $this->assertSame('intra', $first->tax_mode);
        $this->assertSame('4779.00', $first->grand_total); // 4050 + 18%
        $this->assertSame('2026-05-05', $first->due_date->toDateString());
        $this->assertSame('Opening import', $first->narration);
        $this->assertSame(1, Invoice::ofKind('credit_note')->count());
        $this->assertSame(36000, $this->onHand()); // 50 − 10 − 5 + 1
    }

    public function test_errors_name_the_row_and_nothing_is_saved(): void
    {
        $result = $this->import('sales_invoices', [self::HEAD,
            $this->row([0 => '1', 2 => '2026-04-05', 3 => 'Mumbai Retail', 6 => 'Steel Rod 12mm', 9 => '10', 11 => '450']),
            $this->row([0 => '2', 2 => '2026-04-05', 3 => 'Nobody', 6 => 'Steel Rod 12mm', 9 => '1', 11 => '450']),
            $this->row([0 => '3', 2 => '2026-04-05', 3 => 'Mumbai Retail', 6 => 'Gold Bar', 9 => '1', 11 => '450']),
            $this->row([0 => '4', 2 => '2026-04-05', 3 => 'Mumbai Retail', 6 => 'Steel Rod 12mm', 9 => '0', 11 => '450']),
            $this->row([0 => '4', 2 => '2026-04-05', 3 => 'Mumbai Retail', 6 => 'Steel Rod 12mm', 9 => '1', 11 => 'abc']),
            $this->row([0 => '5', 1 => 'purchase', 2 => '2026-04-05', 3 => 'Mumbai Retail', 6 => 'SR12', 9 => '1', 11 => '1']),
            $this->row([0 => '6', 2 => '2026-04-05', 3 => 'Surat Steel', 6 => 'SR12', 9 => '1', 11 => '1']),
        ], commit: true);
        $messages = collect($result['errors'])->pluck('message', 'row');
        $this->assertCount(6, $result['errors']);
        $this->assertStringContainsString('"Nobody" is not an active customer', $messages[3]);
        $this->assertStringContainsString('Row 4: "Gold Bar" is neither a stock item nor a sales ledger', $messages[4]);
        $this->assertSame(['Row 5: enter a quantity greater than zero (up to 3 decimals).', 'Row 6: enter a valid rate (up to 2 decimals).'],
            collect($result['errors'])->where('row', 5)->pluck('message')->all());
        $this->assertStringContainsString('Type: Use sales or credit_note', $messages[7]);
        $this->assertStringContainsString('"Surat Steel" is not an active customer', $messages[8]);
        $this->assertSame(0, Invoice::count()); // the valid invoice 1 was not kept either
    }

    public function test_duplicates_are_refused_or_skipped(): void
    {
        $bill = [self::PHEAD, $this->row([0 => 'B1', 2 => '2026-04-03', 3 => 'Surat Steel', 4 => 'SS/101', 6 => 'SR12', 9 => '5', 11 => '380'])];
        $this->assertSame(1, $this->import('purchase_invoices', $bill)['created']);
        $number = Invoice::firstOrFail()->number();

        // The same supplier bill number again, even with other lines.
        $again = [self::PHEAD, $this->row([0 => 'X', 2 => '2026-04-09', 3 => 'Surat Steel', 4 => 'ss/101', 6 => 'SR12', 9 => '1', 11 => '1'])];
        $result = $this->import('purchase_invoices', $again);
        $this->assertStringContainsString("already entered for Surat Steel with bill number ss/101", $result['errors'][0]['message']);
        $this->assertStringContainsString($number, $result['errors'][0]['message']);
        $result = $this->import('purchase_invoices', $again, skip: true);
        $this->assertSame([0, 1], [$result['created'], $result['skipped']]);
        $this->assertSame(1, Invoice::count());

        // Sales: the same party, date and total.
        $sale = [self::HEAD, $this->row([0 => '1', 2 => '2026-04-05', 3 => 'Mumbai Retail', 6 => 'SR12', 9 => '2', 11 => '450'])];
        $this->assertSame(1, $this->import('sales_invoices', $sale)['created']);
        $result = $this->import('sales_invoices', $sale, skip: true);
        $this->assertSame([0, 1], [$result['created'], $result['skipped']]);
        $this->assertStringContainsString('Same as sales invoice', $result['skipped_rows'][0]['message']);
        $this->assertSame(1, Invoice::ofKind('sales')->count());
        // A different quantity is a different invoice.
        $sale[1][9] = '3';
        $this->assertSame(1, $this->import('sales_invoices', $sale)['created']);
    }

    public function test_screens_templates_and_permissions(): void
    {
        $this->actingAs($this->owner)->get(route('imports.index'))->assertOk()
            ->assertSee('Sales invoices and credit notes')->assertSee('Purchase bills and debit notes')->assertSee('Supplier Bill No');
        $this->get(route('imports.template', ['purchase_invoices', 'csv']))->assertOk();
        $this->get(route('exports.index'))->assertOk()->assertSee('Purchase bills and debit notes');

        $path = $this->csv([self::HEAD, $this->row([0 => '1', 2 => '2026-04-05', 3 => 'Mumbai Retail', 6 => 'SR12', 9 => '2', 11 => '450'])]);
        $this->post(route('imports.preview'), ['type' => 'sales_invoices', 'file' => new UploadedFile($path, 'sales.csv', 'text/csv', null, true)])
            ->assertOk()->assertSee('1');
        $this->assertSame(0, Invoice::count());
        $this->post(route('imports.confirm'))->assertRedirect(route('imports.index'))->assertSessionHas('success', 'Imported 1 sales invoices and credit notes.');
        $this->assertSame(1, Invoice::count());

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('imports.template', ['sales_invoices', 'csv']))->assertForbidden();
        $this->actingAs($viewer)->get(route('imports.index'))->assertDontSee('Sales invoices and credit notes');
    }
}
