<?php

namespace Tests\Feature\Data;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\InvoiceItem;
use App\Models\Party;
use App\Models\StockItem;
use App\Services\Data\ImportService;
use App\Services\Inventory\BatchTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchImportTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;

    protected const ITEM_HEAD = ['Name', 'Unit', 'Opening Qty', 'Opening Rate', 'Tracking', 'Track Expiry', 'Costing', 'Opening Batch', 'Opening Mfg Date', 'Opening Expiry Date', 'Opening Serials'];
    protected const BILL_HEAD = ['Invoice', 'Date', 'Party', 'Supplier Bill No', 'Item', 'Quantity', 'Rate', 'GST Rate', 'Batch', 'Mfg Date', 'Expiry Date', 'Serials'];
    protected const SALE_HEAD = ['Invoice', 'Date', 'Party', 'Item', 'Quantity', 'Rate', 'GST Rate', 'Batch', 'Serials'];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $customer = Account::create(['name' => 'Mumbai Retail', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $customer->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'unregistered']);
        $supplier = Account::create(['name' => 'Pune Pharma', 'account_group_id' => AccountGroup::idFor('sundry_creditors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $supplier->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'unregistered']);
    }

    protected function import(string $type, array $rows, bool $commit = true): array
    {
        $path = tempnam(sys_get_temp_dir(), 'bat').'.csv';
        $h = fopen($path, 'w');
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        fclose($h);
        $service = app(ImportService::class);

        return $service->run($type, $service->read($path, 'csv', $type), $this->owner, $commit, false);
    }

    public function test_stock_items_with_batches_serials_and_bills(): void
    {
        // Problems are reported by row: expiry missing, serial count short, unknown tracking.
        $result = $this->import('stock_items', [self::ITEM_HEAD,
            ['Syrup', 'Nos', '10', '50', 'batch', 'yes', '', 'S-1', '', '', ''],
            ['Charger', 'Nos', '2', '300', 'serial', '', '', '', '', '', 'C1'],
            ['Bandage', 'Nos', '5', '10', 'lots', '', '', '', '', '', ''],
        ], false);
        $messages = collect($result['errors'])->groupBy('row')->map(fn ($g) => $g->pluck('message')->implode(' '));
        $this->assertSame([2, 3, 4], $messages->keys()->all());
        $this->assertStringContainsString('xpiry', $messages[2]);
        $this->assertStringContainsString('erial', $messages[3]);
        $this->assertStringContainsString('racking', $messages[4]);

        $result = $this->import('stock_items', [self::ITEM_HEAD,
            ['Paracetamol 500', 'Nos', '100', '2', 'batch', 'yes', 'fifo', 'P-24A', '01-01-2026', '31-12-2027', ''],
            ['Phone X1', 'Nos', '3', '9000', 'serial', '', '', '', '', '', 'SN1; SN2, SN3'],
        ]);
        $this->assertSame([2, []], [$result['created'], $result['errors']]);

        $para = StockItem::where('name', 'Paracetamol 500')->sole();
        $this->assertSame(['batch', true, 'fifo', 'P-24A', '2026-01-01', '2027-12-31'],
            [$para->tracking, (bool) $para->track_expiry, $para->costing_method, $para->opening_batch_no, $para->opening_mfg_date->toDateString(), $para->opening_expiry_date->toDateString()]);
        $phone = StockItem::where('name', 'Phone X1')->sole();
        $this->assertSame(['serial', "SN1\nSN2\nSN3"], [$phone->tracking, $phone->opening_serials]);

        // A purchase bill brings in a new batch with its dates and two new serials.
        $result = $this->import('purchase_invoices', [self::BILL_HEAD,
            ['B1', '2026-05-02', 'Pune Pharma', 'PP/1', 'Paracetamol 500', '50', '2', '0', 'P-25B', '2026-04-01', '2028-03-31', ''],
            ['B1', '', '', '', 'Phone X1', '2', '9000', '0', '', '', '', 'SN4;SN5'],
        ]);
        $this->assertSame([1, []], [$result['created'], $result['errors']]);
        $line = InvoiceItem::query()->where('batch_no', 'P-25B')->sole();
        $this->assertSame('2028-03-31', $line->expiry_date->toDateString());
        $batches = app(BatchTracker::class)->batches('2027-03-31', [$para->id])->pluck('expiry_date', 'batch_no')->map(fn ($d) => (string) (is_object($d) ? $d->toDateString() : $d));
        $this->assertSame(['P-24A' => '2027-12-31', 'P-25B' => '2028-03-31'], $batches->sortKeys()->all());
        $states = app(BatchTracker::class)->serialStates([$phone->id]);
        $this->assertSame(['SN1', 'SN2', 'SN3', 'SN4', 'SN5'], array_keys($states[$phone->id]));

        // Sales: serials in stock go out; one already sold or unknown is refused with its row.
        $result = $this->import('sales_invoices', [self::SALE_HEAD,
            ['S1', '2026-05-10', 'Mumbai Retail', 'Phone X1', '2', '12000', '0', '', 'SN1,SN4'],
            ['S2', '2026-05-11', 'Mumbai Retail', 'Paracetamol 500', '10', '3', '0', 'P-24A', ''],
        ]);
        $this->assertSame([2, []], [$result['created'], $result['errors']]);
        $result = $this->import('sales_invoices', [self::SALE_HEAD,
            ['S3', '2026-05-12', 'Mumbai Retail', 'Phone X1', '1', '12000', '0', '', 'SN1'],
            ['S4', '2026-05-12', 'Mumbai Retail', 'Phone X1', '1', '12000', '0', '', 'SN9'],
            ['S5', '2026-05-12', 'Mumbai Retail', 'Phone X1', '1', '12000', '0', '', 'SN2'],
        ], false);
        $this->assertSame([2, 3], collect($result['errors'])->pluck('row')->unique()->values()->all());
        $states = app(BatchTracker::class)->serialStates([$phone->id]);
        $this->assertFalse($states[$phone->id]['SN1']->in_stock);
        $this->assertTrue($states[$phone->id]['SN2']->in_stock);

        // Dates that cannot be read are reported, not guessed.
        $result = $this->import('purchase_invoices', [self::BILL_HEAD,
            ['B2', '2026-05-12', 'Pune Pharma', 'PP/2', 'Paracetamol 500', '5', '2', '0', 'P-26C', '', 'next year', ''],
        ], false);
        $this->assertStringContainsString('"next year" is not a date', collect($result['errors'])->pluck('message')->implode(' '));
    }
}
