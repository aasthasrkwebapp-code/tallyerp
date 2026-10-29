<?php

namespace Tests\Feature\Data;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\StockItem;
use App\Models\Unit;
use App\Services\Data\ExportService;
use App\Services\Data\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceExportTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;

    protected function masters(): void
    {
        $c = Account::create(['name' => 'Mumbai Retail', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party', 'maintain_bill_wise' => true,
            'opening_balance' => '5000', 'opening_balance_type' => 'Dr']);
        Party::create(['account_id' => $c->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'regular', 'gstin' => '27ABCDE1234F1Z5']);
        $s = Account::create(['name' => 'Surat Steel', 'account_group_id' => AccountGroup::idFor('sundry_creditors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $s->id, 'state' => 'Gujarat', 'gst_registration_type' => 'regular', 'gstin' => '24ABCDE1234F1Z9']);
        $unit = Unit::where('symbol', 'Nos')->value('id');
        StockItem::create(['name' => 'Steel Rod 12mm', 'code' => 'SR12', 'unit_id' => $unit, 'hsn_sac' => '7214', 'gst_rate' => '18']);
        StockItem::create(['name' => 'Phone X1', 'code' => 'PX1', 'unit_id' => $unit, 'hsn_sac' => '8517', 'gst_rate' => '18', 'tracking' => 'serial']);
    }

    protected function file(array $headers, iterable $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'exp').'.csv';
        $h = fopen($path, 'w');
        fputcsv($h, $headers);
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        fclose($h);

        return $path;
    }

    protected function import(string $type, array $headers, iterable $rows): array
    {
        $service = app(ImportService::class);

        return $service->run($type, $service->read($this->file($headers, $rows), 'csv', $type), $this->owner, true);
    }

    /** @return array{0: list<string>, 1: list<array>} */
    protected function export(string $type, ?string $from = null, ?string $to = null): array
    {
        [$headers, $rows] = app(ExportService::class)->export($type, $from, $to);

        return [$headers, collect($rows)->map(fn ($r) => array_map('strval', array_values($r)))->all()];
    }

    public function test_invoices_and_opening_bills_export_in_the_import_layout_and_import_elsewhere(): void
    {
        [$this->owner, $this->company] = $this->createCompanyWithOwner();
        $this->masters();
        $bills = ['Invoice', 'Type', 'Date', 'Party', 'Supplier Bill No', 'Item', 'Ledger', 'Description', 'Quantity', 'Rate', 'Discount %', 'GST Rate', 'Narration', 'Serials'];
        $this->assertSame([], $this->import('purchase_invoices', $bills, [
            ['B1', 'purchase', '2026-04-03', 'Surat Steel', 'SS/101', 'SR12', '', '', '50', '380', '', '18', 'April stock', ''],
            ['B1', '', '', '', '', 'Purchase', '', 'Freight', '1', '500', '', '5', '', ''],
            ['B2', 'purchase', '2026-04-04', 'Surat Steel', 'SS/102', 'PX1', '', '', '2', '9000', '', '18', '', 'S1; S2'],
        ])['errors']);
        $sales = ['Invoice', 'Date', 'Party', 'Item', 'Quantity', 'Rate', 'Discount %', 'GST Rate', 'Serials'];
        $this->assertSame([], $this->import('sales_invoices', $sales, [
            ['1', '2026-04-10', 'Mumbai Retail', 'SR12', '10', '450', '5', '18', ''],
            ['1', '', '', 'PX1', '1', '12000', '', '18', 'S2'],
        ])['errors']);
        $this->assertSame([], $this->import('opening_bills', ['Party', 'Bill Ref', 'Date', 'Amount'], [['Mumbai Retail', 'OLD/9', '2026-03-01', '5000']])['errors']);
        // A cancelled sale is not exported.
        $cancelled = app(\App\Services\Billing\InvoiceService::class)->save(null, 'sales', ['date' => '2026-04-11', 'party_account_id' => Account::where('name', 'Mumbai Retail')->value('id'),
            'items' => [['stock_item_id' => StockItem::where('code', 'SR12')->value('id'), 'account_id' => Account::idFor('sales'), 'quantity' => '1', 'rate' => '1', 'gst_rate' => '0']]], $this->owner);
        app(\App\Services\Billing\InvoiceService::class)->cancel($cancelled, $this->owner, 'Wrong');

        [$pHead, $pRows] = $this->export('purchase_invoices');
        $this->assertSame(array_column(ImportService::columns('purchase_invoices'), 0), $pHead);
        $col = fn (array $row, string $name) => $row[array_search($name, $pHead, true)];
        $this->assertCount(3, $pRows);
        $this->assertSame(['purchase', '2026-04-03', 'Surat Steel', 'SS/101', 'SR12', '50', '380.00', '18', 'April stock'],
            [$col($pRows[0], 'Type'), $col($pRows[0], 'Date'), $col($pRows[0], 'Party'), $col($pRows[0], 'Supplier Bill No'), $col($pRows[0], 'Item'), $col($pRows[0], 'Quantity'), $col($pRows[0], 'Rate'), $col($pRows[0], 'GST Rate'), $col($pRows[0], 'Narration')]);
        $this->assertSame(['', 'Purchase', 'Freight'], [$col($pRows[1], 'Type'), $col($pRows[1], 'Item'), $col($pRows[1], 'Description')]);
        $this->assertSame('S1, S2', $col($pRows[2], 'Serials'));
        [$sHead, $sRows] = $this->export('sales_invoices');
        $this->assertCount(2, $sRows);
        $this->assertSame('5', $sRows[0][array_search('Discount %', $sHead, true)]);
        $this->assertSame([], $this->export('sales_invoices', '2026-05-01')[1]);
        [$oHead, $oRows] = $this->export('opening_bills');
        $this->assertSame([['Mumbai Retail', 'OLD/9', '2026-03-01', '', '5000.00', 'Dr']], $oRows);

        // The files import into another company with the same masters, giving the same totals.
        $totals = Invoice::query()->whereHas('voucher', fn ($q) => $q->where('status', 'posted'))->orderBy('date')->pluck('grand_total')->all();
        [, $other] = $this->createCompanyWithOwner(['name' => 'Branch Co'], $this->owner);
        $this->masters();
        foreach ([['purchase_invoices', $pHead, $pRows], ['sales_invoices', $sHead, $sRows], ['opening_bills', $oHead, $oRows]] as [$type, $head, $rows]) {
            $this->assertSame([], $this->import($type, $head, $rows)['errors'], $type);
        }
        $this->assertSame($totals, Invoice::query()->whereHas('voucher', fn ($q) => $q->where('status', 'posted'))->orderBy('date')->pluck('grand_total')->all());
        $this->assertSame($other->id, Invoice::query()->first()->company_id);

        // Screens: listed with dates, downloaded, and limited by permission.
        $this->actingAs($this->owner)->get(route('exports.index'))->assertOk()->assertSee('Sales invoices and credit notes')->assertSee('Opening bills of parties');
        $this->get(route('exports.download', ['sales_invoices', 'format' => 'xlsx']))->assertOk();
        $viewer = $this->addMember($other, 'viewer');
        $this->actingAs($viewer)->get(route('exports.download', 'opening_bills'))->assertForbidden();
    }
}
