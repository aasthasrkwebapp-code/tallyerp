<?php

namespace Tests\Feature\Data;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Employee;
use App\Models\EmployeePayHead;
use App\Models\Party;
use App\Models\PayHead;
use App\Services\Billing\InvoiceService;
use App\Services\Payroll\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdfTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner(['name' => 'Asha Traders', 'state' => 'Maharashtra', 'gstin' => '27ABCDE1234F1Z5']);
        $this->company->update(['settings' => ['allow_future_dated_vouchers' => true]]);
    }

    /** Text drawn in a dompdf file, good enough to look for words (streams are not compressed in tests). */
    protected function assertPdf($response, string $filename): string
    {
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString($filename, $response->headers->get('content-disposition'));
        $content = $response instanceof \Illuminate\Testing\TestResponse && $response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            ? $response->streamedContent() : $response->getContent();
        $this->assertStringStartsWith('%PDF-', $content);

        return $content;
    }

    public function test_invoice_pdf(): void
    {
        $customer = Account::create(['name' => 'Mumbai Retail', 'account_group_id' => AccountGroup::idFor('sundry_debtors'), 'account_type' => 'party', 'maintain_bill_wise' => true]);
        Party::create(['account_id' => $customer->id, 'state' => 'Maharashtra', 'gst_registration_type' => 'regular', 'gstin' => '27AAAAA1111A1Z5']);
        $invoice = app(InvoiceService::class)->save(null, 'sales', [
            'date' => '2026-10-02', 'party_account_id' => $customer->id,
            'items' => [['account_id' => Account::idFor('sales'), 'description' => 'Steel rods', 'quantity' => '2', 'rate' => '1000', 'gst_rate' => '18']],
        ], $this->owner, 'post');

        $this->actingAs($this->owner);
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee(route('invoices.pdf', $invoice), false);
        $this->assertPdf($this->get(route('invoices.pdf', $invoice)), '.pdf');

        // Another company's invoice is not reachable.
        [$other] = $this->createCompanyWithOwner(['name' => 'Other Co']);
        $this->actingAs($other)->get(route('invoices.pdf', $invoice))->assertNotFound();
    }

    public function test_report_and_payslip_pdfs(): void
    {
        $this->actingAs($this->owner);
        $period = ['from' => '2026-04-01', 'to' => '2027-03-31', 'export' => 'pdf'];
        $this->assertPdf($this->get(route('reports.trial-balance', $period)), 'trial-balance');
        $this->assertPdf($this->get(route('reports.profit-loss', $period)), '.pdf');
        $this->assertPdf($this->get(route('reports.day-book', $period)), '.pdf');
        $this->assertPdf($this->get(route('reports.balance-sheet', ['to' => '2027-03-31', 'export' => 'pdf'])), 'balance-sheet');
        $this->assertPdf($this->get(route('gst.gstr1', ['from' => '2026-10-01', 'to' => '2026-10-31', 'export' => 'pdf', 'section' => 'b2b'])), '.pdf');
        $this->assertPdf($this->get(route('stock-summary', ['to' => '2026-10-31', 'export' => 'pdf'])), '.pdf');

        $employee = Employee::create(['code' => 'E1', 'name' => 'Asha Patil', 'date_of_joining' => '2025-01-01']);
        EmployeePayHead::create(['employee_id' => $employee->id, 'pay_head_id' => PayHead::where('system_key', 'basic')->value('id'), 'amount' => '12000']);
        $run = app(PayrollService::class)->prepare('2026-10', $this->owner);
        $slip = $run->payslips->first();
        $this->assertPdf($this->get(route('payroll-runs.payslip', [$run, $slip, 'export' => 'pdf'])), 'payslip-october-2026-e1.pdf');
        $this->assertPdf($this->get(route('payroll-runs.show', [$run, 'export' => 'pdf'])), '.pdf');

        // A user without export permission cannot download report PDFs.
        $sales = $this->addMember($this->company, 'sales');
        $this->actingAs($sales)->get(route('reports.trial-balance', $period))->assertForbidden();
    }

    public function test_profit_and_loss_and_balance_sheet_export(): void
    {
        app(\App\Services\Accounting\VoucherService::class)->create([
            'voucher_type_id' => \App\Models\VoucherType::forBase('receipt')->id, 'date' => '2026-10-01',
            'entries' => [['account_id' => Account::idFor('cash'), 'side' => 'Dr', 'amount' => '2500'], ['account_id' => Account::idFor('interest_received'), 'side' => 'Cr', 'amount' => '2500']],
        ], $this->owner, \App\Models\Voucher::POSTED);

        $this->actingAs($this->owner);
        $pl = $this->get(route('reports.profit-loss', ['from' => '2026-04-01', 'to' => '2027-03-31', 'export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Interest Received', $pl);
        $this->assertStringContainsString('"Net profit",,2500.00', $pl);

        $bs = $this->get(route('reports.balance-sheet', ['to' => '2027-03-31', 'export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Total liabilities",,2500.00', $bs);
        $this->assertStringContainsString('"Total assets",,2500.00', $bs);
        $this->get(route('reports.profit-loss', ['from' => '2026-04-01', 'to' => '2027-03-31']))->assertOk()->assertSee('export=pdf', false);
    }
}
