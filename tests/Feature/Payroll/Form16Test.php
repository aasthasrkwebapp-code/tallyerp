<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\EmployeePayHead;
use App\Models\FinancialYear;
use App\Models\PayHead;
use App\Models\TaxDeclaration;
use App\Models\TdsReturn;
use App\Services\Payroll\Form16;
use App\Services\Payroll\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Meera: basic 2,00,000 + HRA 40% + special 20,000 = 3,00,000 a month, TDS 10,000 a month,
 * paid April to June 2026 (Q1), plus 3,00,000 from a previous employer.
 */
class Form16Test extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $company;
    protected Employee $meera;
    protected FinancialYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->company] = $this->createCompanyWithOwner(['state' => 'Maharashtra', 'tan' => 'MUMA12345B']);
        $this->meera = Employee::create(['code' => 'E01', 'name' => 'Meera Joshi', 'date_of_joining' => '2026-04-01', 'pan' => 'ABCPJ1234K', 'date_of_birth' => '1985-06-10']);
        foreach (['basic' => '200000', 'hra' => null, 'special' => '20000', 'pt' => null, 'tds' => '10000'] as $key => $amount) {
            EmployeePayHead::create(['employee_id' => $this->meera->id, 'pay_head_id' => PayHead::where('system_key', $key)->value('id'), 'amount' => $amount]);
        }
        foreach (['2026-04', '2026-05', '2026-06'] as $month) {
            $run = app(PayrollService::class)->prepare($month, $this->owner);
            app(PayrollService::class)->post($run, $this->owner);
        }
        // A draft payroll does not count.
        app(PayrollService::class)->prepare('2026-07', $this->owner);
        $this->year = FinancialYear::where('is_current', true)->firstOrFail();
    }

    public function test_tax_on_slabs_rebate_surcharge_and_senior_exemption(): void
    {
        $tax = fn (int $rupees, string $regime, ?int $age = null) => app(Form16::class)->tax($rupees * 100, $regime, $age);

        $this->assertSame([6000000, 6000000, 0], [$tax(1200000, 'new')['on_income'], $tax(1200000, 'new')['rebate'], $tax(1200000, 'new')['total']]);
        // Just over ₹12 lakh: marginal relief keeps the tax to the excess (10,000) plus cess.
        $this->assertSame([6150000, 5150000, 1040000], [$tax(1210000, 'new')['on_income'], $tax(1210000, 'new')['rebate'], $tax(1210000, 'new')['total']]);
        $this->assertSame(34320000, $tax(2500000, 'new')['total']);
        $high = $tax(6000000, 'new');
        $this->assertSame([10, 13800000, 157872000], [$high['surcharge_rate'], $high['surcharge'], $high['total']]);

        $this->assertSame(11700000, $tax(1000000, 'old', 40)['total']);
        $this->assertSame([750000, 0], [$tax(450000, 'old', 62)['on_income'], $tax(450000, 'old', 62)['total']]); // ₹3 lakh exempt at 60
        $this->assertSame(8320000, $tax(900000, 'old', 82)['total']); // ₹5 lakh exempt at 80
        $this->assertSame(['0', '5', '20'], array_map(fn ($s) => (string) $s['rate'], $tax(700000, 'old', 30)['slabs']));
    }

    public function test_computation_from_posted_payroll_and_declaration(): void
    {
        $f = app(Form16::class);
        $salary = $f->salary($this->meera, $this->year);
        $this->assertSame(3, $salary['months']);
        $this->assertSame(90000000, $salary['gross']);
        $this->assertSame(['Basic Salary' => 60000000, 'House Rent Allowance' => 24000000, 'Special Allowance' => 6000000], $salary['heads']);
        $this->assertSame(60000, $salary['professional_tax']);
        $this->assertSame([90000000, 3000000], [$salary['quarters'][1]['paid'], $salary['quarters'][1]['tds']]);
        $this->assertSame(0, $salary['quarters'][2]['paid']);

        $declaration = TaxDeclaration::create(['employee_id' => $this->meera->id, 'financial_year_id' => $this->year->id, 'regime' => 'old',
            'hra_exemption' => '60000', 'home_loan_interest' => '250000', 'section_80c' => '200000', 'section_80d' => '25000',
            'previous_employer_salary' => '300000', 'previous_employer_tds' => '20000']);
        $old = $f->compute($this->meera, $this->year);
        $this->assertSame(120000000, $old['gross']);
        $this->assertSame(108940000, $old['salary_income']); // − 60,000 HRA − 50,000 standard − 600 PT
        $this->assertSame(-20000000, $old['house_property']); // interest capped at 2 lakh
        $this->assertSame(['80C' => 15000000, '80D' => 2500000], $old['chapter_via']);
        $this->assertSame(71440000, $old['taxable']);
        $this->assertSame(5760000, $old['tax']['total']); // 12,500 + 20% of 2,14,400 + 4% cess, rounded
        $this->assertSame([5000000, 760000], [$old['tds'], $old['balance']]);

        $declaration->update(['regime' => 'new']);
        $new = $f->compute($this->meera, $this->year);
        $this->assertSame([0, 112500000, 0], [$new['professional_tax'], $new['taxable'], $new['tax']['total']]);
        $this->assertSame([], $new['chapter_via']);
        $this->assertContains('section_80c', $new['ignored']);
        $this->assertSame(-5000000, $new['balance']);
    }

    public function test_screens_pdf_declaration_returns_and_permissions(): void
    {
        $this->actingAs($this->owner)->get(route('form16.index'))->assertOk()
            ->assertSee('Meera Joshi')->assertSee('ABCPJ1234K')->assertSee('9,00,000.00')->assertSee('30,000.00 excess');
        $csv = $this->get(route('form16.index', ['export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('E01,"Meera Joshi",ABCPJ1234K,New', $csv);

        $this->put(route('form16.declaration.update', [$this->meera, 'year' => $this->year->id]), ['regime' => 'old', 'section_80c' => '150000', 'hra_exemption' => '-5'])
            ->assertSessionHasErrors('hra_exemption');
        $this->put(route('form16.declaration.update', [$this->meera, 'year' => $this->year->id]), ['regime' => 'old', 'section_80c' => '150000'])
            ->assertRedirect(route('form16.show', [$this->meera, 'year' => $this->year->id]));
        $this->assertSame('150000.00', TaxDeclaration::firstOrFail()->section_80c);
        $this->get(route('form16.declaration', [$this->meera, 'year' => $this->year->id]))->assertOk()->assertSee('150000.00', false);

        $this->put(route('form16.returns', ['year' => $this->year->id]), ['receipt' => [1 => 'qwertyui', 2 => 'bad no!']])->assertSessionHasErrors('receipt.2');
        $this->put(route('form16.returns', ['year' => $this->year->id]), ['receipt' => [1 => 'qwertyui'], 'filed_on' => [1 => '2026-07-31']])->assertRedirect();
        $this->assertSame('QWERTYUI', TdsReturn::where('quarter', 1)->value('receipt_number'));

        $this->get(route('form16.show', [$this->meera, 'year' => $this->year->id]))->assertOk()
            ->assertSee('Part A')->assertSee('QWERTYUI')->assertSee('Section 80C')->assertSee('Old regime');
        $pdf = $this->get(route('form16.show', [$this->meera, 'year' => $this->year->id, 'export' => 'pdf']));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));

        $this->put(route('form16.returns', ['year' => $this->year->id]), ['receipt' => [1 => '']])->assertRedirect();
        $this->assertSame(0, TdsReturn::count());

        $viewer = $this->addMember($this->company, 'viewer');
        $this->actingAs($viewer)->get(route('form16.index'))->assertOk()->assertDontSee('Save receipt numbers');
        $this->actingAs($viewer)->get(route('form16.declaration', [$this->meera, 'year' => $this->year->id]))->assertForbidden();
        $this->actingAs($viewer)->put(route('form16.returns'), ['receipt' => [1 => 'X']])->assertForbidden();
    }
}
