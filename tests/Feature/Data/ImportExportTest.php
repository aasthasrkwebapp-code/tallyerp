<?php

namespace Tests\Feature\Data;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Party;
use App\Models\StockGroup;
use App\Models\StockItem;
use App\Models\Voucher;
use App\Services\Data\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportExportTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner(['name' => 'Asha Traders', 'state' => 'Maharashtra']);
        $this->company->update(['settings' => ['allow_future_dated_vouchers' => true]]);
    }

    protected function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        $h = fopen($path, 'w');
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        fclose($h);

        return $path;
    }

    protected function xlsx(array $rows): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    protected function import(string $type, string $path, bool $commit = true, bool $skip = false): array
    {
        $service = app(ImportService::class);

        return $service->run($type, $service->read($path, pathinfo($path, PATHINFO_EXTENSION), $type), $this->owner, $commit, $skip);
    }

    public function test_ledgers_import_only_when_every_row_is_valid(): void
    {
        $file = $this->csv([
            ['Name', 'Group', 'Opening Balance', 'Dr/Cr'],
            ['Office Rent', 'Indirect Expenses', '', ''],
            ['Security Deposit', 'loans & advances (asset)', '25,000', 'Dr'],
            ['Bad Group', 'No Such Group', '', ''],
            ['Office Rent', 'Indirect Expenses', '', ''],
            ['Telephone', 'Indirect Expenses', '500', 'Dr'],
            ['Customer X', 'Sundry Debtors', '', ''],
        ]);

        $preview = $this->import('ledgers', $file, commit: true);
        $this->assertSame(6, $preview['total']);
        $this->assertSame([4, 5, 6, 7], array_column($preview['errors'], 'row'));
        $messages = implode(' | ', array_column($preview['errors'], 'message'));
        $this->assertStringContainsString('Group "No Such Group" does not exist', $messages);
        $this->assertStringContainsString('"Office Rent" already exists', $messages); // duplicate inside the file
        $this->assertStringContainsString('cannot carry an opening balance', $messages);
        $this->assertStringContainsString('Import customers and suppliers', $messages);
        $this->assertFalse(Account::where('name', 'Office Rent')->exists()); // nothing saved

        $good = $this->csv([['Name', 'Group', 'Opening Balance', 'Dr/Cr'], ['Office Rent', 'Indirect Expenses', '', ''], ['Security Deposit', 'Loans & Advances (Asset)', '25,000', '']]);
        $dry = $this->import('ledgers', $good, commit: false);
        $this->assertSame([2, 0], [$dry['created'], count($dry['errors'])]);
        $this->assertFalse(Account::where('name', 'Office Rent')->exists());

        $done = $this->import('ledgers', $good);
        $this->assertSame(2, $done['created']);
        $deposit = Account::where('name', 'Security Deposit')->first();
        $this->assertSame(['25000.00', 'Dr', 'general'], [$deposit->opening_balance, $deposit->opening_balance_type, $deposit->account_type]);
        $this->assertTrue(AuditLog::where('event', 'data_imported')->exists());

        // Again with "skip existing": both rows are skipped, nothing fails.
        $again = $this->import('ledgers', $good, skip: true);
        $this->assertSame([0, 2, 0], [$again['created'], $again['skipped'], count($again['errors'])]);
    }

    public function test_customers_are_validated_like_the_form(): void
    {
        $file = $this->csv([
            ['Name', 'GST Type', 'GSTIN', 'State', 'City', 'PIN', 'Phone', 'Opening Balance', 'Credit Days'],
            ['Mumbai Retail', '', '27abcde1234f1z5', 'maharashtra', 'Mumbai', '400001', '9820000000', '5000', '30'],
            ['Wrong State Co', 'regular', '29ABCDE1234F1Z5', 'Maharashtra', '', '', '', '', ''],
            ['Walk-in', '', '', 'Gujarat', '', '12345', '', '', ''],
            ['Copy Of Mumbai', '', '27ABCDE1234F1Z5', 'Maharashtra', '', '', '', '', ''],
        ]);
        $result = $this->import('customers', $file);
        $messages = collect($result['errors'])->groupBy('row')->map(fn ($g) => $g->pluck('message')->implode(' '));
        $this->assertStringContainsString('state code should be 27', $messages[3]);
        $this->assertStringContainsString('PIN code must be 6 digits', $messages[4]);
        $this->assertStringContainsString('already belongs to Mumbai Retail', $messages[5]);

        $ok = $this->csv([
            ['Name', 'GSTIN', 'State', 'City', 'Opening Balance', 'Credit Days'],
            ['Mumbai Retail', '27abcde1234f1z5', 'Maharashtra', 'Mumbai', '5000', '30'],
            ['Walk-in', '', 'Gujarat', '', '', ''],
        ]);
        $this->assertSame(2, $this->import('customers', $ok)['created']);
        $mumbai = Account::where('name', 'Mumbai Retail')->with('partyDetails', 'group')->first();
        $this->assertSame(['party', 'Sundry Debtors', 'Dr', true, 30], [$mumbai->account_type, $mumbai->group->name, $mumbai->opening_balance_type, (bool) $mumbai->maintain_bill_wise, $mumbai->credit_period_days]);
        $this->assertSame(['regular', '27ABCDE1234F1Z5', 'ABCDE1234F', 'Maharashtra'], [$mumbai->partyDetails->gst_registration_type, $mumbai->partyDetails->gstin, $mumbai->partyDetails->pan, $mumbai->partyDetails->state]);
        $this->assertSame('unregistered', Party::whereHas('account', fn ($q) => $q->where('name', 'Walk-in'))->value('gst_registration_type'));

        // Suppliers default to Sundry Creditors and Cr.
        $this->import('suppliers', $this->csv([['Name', 'State', 'Opening Balance'], ['Pune Steel', 'Maharashtra', '1200']]));
        $this->assertSame(['Sundry Creditors', 'Cr'], [Account::where('name', 'Pune Steel')->first()->group->name, Account::where('name', 'Pune Steel')->value('opening_balance_type')]);
    }

    public function test_stock_items_from_excel(): void
    {
        $file = $this->xlsx([
            ['Name', 'Unit', 'Stock Group', 'HSN/SAC', 'GST Rate', 'Sales Rate', 'Opening Qty', 'Opening Rate'],
            ['Steel Rod 12mm', 'nos', 'Steel', '7214', 18, 450, 100, 380],
            ['Cement', 'Bags', 'Building', '2523', 28, 400, 10, 350],
            ['Wire', 'Kg', 'Steel', '7217', 18, 90, 2.5, 80],
        ]);
        $result = $this->import('stock_items', $file);
        $this->assertSame([3], array_column($result['errors'], 'row'));
        $this->assertStringContainsString('Unit "Bags" does not exist', $result['errors'][0]['message']);

        $ok = $this->xlsx([
            ['Name', 'Unit', 'Stock Group', 'HSN/SAC', 'GST Rate', 'Sales Rate', 'Opening Qty', 'Opening Rate'],
            ['Steel Rod 12mm', 'nos', 'Steel', '7214', 18, 450, 100, 380],
            ['Wire', 'Kg', 'steel', '7217', 18, 90, 2.5, 80],
        ]);
        $this->assertSame(2, $this->import('stock_items', $ok)['created']);
        $rod = StockItem::where('name', 'Steel Rod 12mm')->first();
        $this->assertSame(['38000.00', '18.00', '7214'], [$rod->opening_value, $rod->gst_rate, $rod->hsn_sac]);
        $this->assertNotNull($rod->opening_godown_id);
        $this->assertSame(1, StockGroup::count()); // "Steel" created once
        $this->assertSame('200.00', StockItem::where('name', 'Wire')->value('opening_value'));
    }

    public function test_vouchers_import_balanced_and_once(): void
    {
        $this->import('ledgers', $this->csv([['Name', 'Group'], ['Office Rent', 'Indirect Expenses']]));
        $rows = [
            ['Voucher', 'Date', 'Type', 'Ledger', 'Debit', 'Credit', 'Narration', 'Reference'],
            ['A1', '05-10-2026', 'journal', 'Office Rent', '15,000', '', 'Rent October', 'R-10'],
            ['A1', '05-10-2026', 'journal', 'Capital', '', '15000', '', ''],
            ['A2', '2026-10-06', 'payment', 'Office Rent', '500', '', 'Tea', ''],
            ['A2', '2026-10-06', 'payment', 'Cash', '', '500', '', ''],
        ];
        $bad = array_merge($rows, [
            ['A3', '2026-10-07', 'journal', 'Office Rent', '100', '', '', ''],
            ['A3', '2026-10-07', 'journal', 'Capital', '', '90', '', ''],
            ['A4', '2026-10-07', 'sales', 'Office Rent', '100', '', '', ''],
            ['A5', '2026-10-07', 'journal', 'Nobody', '100', '', '', ''],
            ['A5', '2026-10-07', 'journal', 'Capital', '', '100', '', ''],
        ]);
        $result = $this->import('vouchers', $this->csv($bad));
        $this->assertSame(5, $result['total']);
        $byRow = collect($result['errors'])->groupBy('row')->map(fn ($g) => $g->pluck('message')->implode(' '));
        $this->assertStringContainsString('must equal total credits', $byRow[6]);
        $this->assertStringContainsString('Sales and purchases are imported as invoices', $byRow[8]);
        $this->assertStringContainsString('ledger "Nobody" does not exist', $byRow[9]);
        $this->assertSame(0, Voucher::count());

        $this->assertSame(2, $this->import('vouchers', $this->csv($rows))['created']);
        $journal = Voucher::where('reference', 'R-10')->first();
        $this->assertSame(['2026-10-05', 'posted', '15000.00', 'Rent October'], [$journal->date->toDateString(), $journal->status, $journal->total, $journal->narration]);

        // The same file again is caught as a duplicate, or skipped when asked.
        $again = $this->import('vouchers', $this->csv($rows));
        $this->assertStringContainsString('looks like', $again['errors'][0]['message']);
        $skipped = $this->import('vouchers', $this->csv($rows), skip: true);
        $this->assertSame([0, 2], [$skipped['created'], $skipped['skipped']]);
        $this->assertSame(2, Voucher::count());
    }

    public function test_missing_columns_and_empty_files_are_refused(): void
    {
        $this->actingAs($this->owner);
        $file = new UploadedFile($this->csv([['Name', 'Opening']]), 'ledgers.csv', 'text/csv', null, true);
        $this->post(route('imports.preview'), ['type' => 'ledgers', 'file' => $file])->assertSessionHasErrors('file');
        $this->assertStringContainsString('Missing column(s): Group', session('errors')->first('file'));

        $empty = new UploadedFile($this->csv([['Name', 'Group']]), 'empty.csv', 'text/csv', null, true);
        $this->post(route('imports.preview'), ['type' => 'ledgers', 'file' => $empty])->assertSessionHasErrors('file');
    }

    public function test_screens_preview_confirm_errors_and_export_round_trip(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('imports.index'))->assertOk()->assertSee('Stock items');
        $this->get(route('imports.template', ['customers', 'xlsx']))->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('Voucher,Date,Type,Ledger', $this->get(route('imports.template', ['vouchers', 'csv']))->assertOk()->streamedContent());

        $bad = new UploadedFile($this->csv([['Name', 'State', 'PIN'], ['A Co', 'Maharashtra', '12']]), 'c.csv', 'text/csv', null, true);
        $this->post(route('imports.preview'), ['type' => 'customers', 'file' => $bad])->assertOk()->assertSee('Nothing has been imported')->assertSee('PIN code must be 6 digits');
        $this->assertStringContainsString('PIN code must be 6 digits', $this->get(route('imports.errors'))->assertOk()->streamedContent());

        $good = new UploadedFile($this->csv([['Name', 'State', 'GSTIN'], ['A Co', 'Maharashtra', '27ABCDE1234F1Z5'], ['B Co', 'Karnataka', '']]), 'c.csv', 'text/csv', null, true);
        $this->post(route('imports.preview'), ['type' => 'customers', 'file' => $good])->assertOk()->assertSee('Every row is valid')->assertSee('Import 2 customers');
        $this->assertFalse(Account::where('name', 'A Co')->exists());
        $this->post(route('imports.confirm'))->assertRedirect(route('imports.index'))->assertSessionHas('success', 'Imported 2 customers.');
        $this->assertTrue(Account::where('name', 'A Co')->exists());
        $this->post(route('imports.confirm'))->assertRedirect(route('imports.index'))->assertSessionHas('error');

        // Export customers to Excel and import them into a second company.
        $this->get(route('exports.index'))->assertOk();
        $response = $this->get(route('exports.download', ['customers', 'format' => 'xlsx']))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray();
        $this->assertSame(['Name', 'Group', 'GST Type'], array_slice($sheet[0], 0, 3));
        $this->assertSame('A Co', $sheet[1][0]);

        [, $other] = $this->createCompanyWithOwner(['name' => 'Second Co'], $this->owner);
        $this->assertSame(2, $this->import('customers', $path)['created']);
        $this->assertSame('27ABCDE1234F1Z5', Party::whereHas('account', fn ($q) => $q->where('name', 'A Co'))->value('gstin'));
        $this->assertSame($other->id, Account::where('name', 'B Co')->value('company_id'));

        // Vouchers export as CSV with one line per ledger.
        $this->useCompany($this->company);
        $this->import('vouchers', $this->csv([['Voucher', 'Date', 'Type', 'Ledger', 'Debit', 'Credit'], ['1', '2026-10-05', 'receipt', 'Cash', '100', ''], ['1', '2026-10-05', 'receipt', 'Capital', '', '100']]));
        $csv = $this->get(route('exports.download', ['vouchers', 'format' => 'csv', 'from' => '2026-04-01', 'to' => '2027-03-31']))->assertOk()->streamedContent();
        $this->assertStringContainsString('2026-10-05,receipt,Cash,100.00', $csv);

        // Reports download as Excel too.
        $tb = $this->get(route('reports.trial-balance', ['from' => '2026-04-01', 'to' => '2027-03-31', 'export' => 'xlsx']))->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $tb->headers->get('content-type'));
        $this->assertStringContainsString('.xlsx', $tb->headers->get('content-disposition'));
    }

    public function test_permissions(): void
    {
        $accountant = $this->addMember($this->company, 'accountant');
        $this->actingAs($accountant)->get(route('imports.index'))->assertForbidden();
        $this->get(route('exports.index'))->assertOk();
        $this->get(route('exports.download', ['ledgers', 'format' => 'csv']))->assertOk();
        $this->get(route('exports.download', ['employees', 'format' => 'csv']))->assertNotFound();

        $sales = $this->addMember($this->company, 'sales');
        $this->actingAs($sales)->get(route('exports.index'))->assertForbidden();

        // An admin who can import still needs the module's create permission for each type.
        $this->actingAs($this->owner)->get(route('imports.index'))->assertOk()->assertSee('Vouchers');
    }
}
