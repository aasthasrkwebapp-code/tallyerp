<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\FinancialYear;
use App\Models\TaxDeclaration;
use App\Models\TdsReturn;
use App\Services\Payroll\Form16;
use App\Support\CsvExport;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Form 16: yearly salary TDS certificates, employee tax declarations and 24Q receipt numbers. */
class Form16Controller extends Controller
{
    public function __construct(protected Form16 $form16) {}

    public function index(Request $request)
    {
        $year = $this->year($request);
        $rows = $year ? $this->form16->summary($year) : collect();

        if ($year && CsvExport::requested($request)) {
            $f = fn (int $p) => Money::format($p, paiseInput: true);

            return CsvExport::download("form-16-summary-{$year->name}.csv",
                ['Code', 'Employee', 'PAN', 'Regime', 'Gross salary', 'Taxable income', 'Tax payable', 'TDS deducted', 'Balance (payable + / excess −)'],
                $rows->map(fn ($r) => [$r->employee->code, $r->employee->name, $r->employee->pan, ucfirst($r->regime), $f($r->gross), $f($r->taxable),
                    $f($r->tax['total']), $f($r->tds), Money::format($r->balance, paiseInput: true)]));
        }

        return view('payroll.form16.index', [
            'year' => $year, 'years' => FinancialYear::query()->orderByDesc('start_date')->get(), 'rows' => $rows,
            'returns' => $year ? $this->form16->returns($year) : [], 'quarters' => $year ? $this->form16->quarterLabels($year) : [],
            'company' => app(CurrentCompany::class)->get(),
        ]);
    }

    public function show(Request $request, Employee $employee)
    {
        $year = $this->year($request) ?? abort(404);
        $data = $this->form16->compute($employee, $year) + [
            'employee' => $employee, 'year' => $year, 'company' => app(CurrentCompany::class)->get(),
            'returns' => $this->form16->returns($year), 'quarters' => $this->form16->quarterLabels($year),
        ];

        if ($request->input('export') === 'pdf') {
            AuditLog::record('form16_printed', $employee, null, ['year' => $year->name], "Form 16 {$year->name} printed for {$employee->name}");
            $name = \Illuminate\Support\Str::slug("form-16 {$year->name} {$employee->code}").'.pdf';

            return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.form16', $data)->setPaper('a4')->download($name);
        }

        return view('payroll.form16.show', $data);
    }

    public function declaration(Request $request, Employee $employee)
    {
        $year = $this->year($request) ?? abort(404);

        return view('payroll.form16.declaration', [
            'employee' => $employee, 'year' => $year,
            'declaration' => TaxDeclaration::query()->where('employee_id', $employee->id)->where('financial_year_id', $year->id)->first() ?? new TaxDeclaration(['regime' => 'new']),
        ]);
    }

    public function saveDeclaration(Request $request, Employee $employee)
    {
        $year = $this->year($request) ?? abort(404);
        $rules = ['regime' => ['required', Rule::in(array_keys(TaxDeclaration::REGIMES))]];
        foreach (array_keys(TaxDeclaration::AMOUNTS) as $field) {
            $rules[$field] = ['nullable', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'];
        }
        $data = $request->validate($rules);
        foreach (array_keys(TaxDeclaration::AMOUNTS) as $field) {
            $data[$field] = $data[$field] ?? 0;
        }

        TaxDeclaration::query()->updateOrCreate(['employee_id' => $employee->id, 'financial_year_id' => $year->id], $data);

        return redirect()->route('form16.show', [$employee, 'year' => $year->id])->with('success', "Tax declaration for {$year->name} saved.");
    }

    public function saveReturns(Request $request)
    {
        $year = $this->year($request) ?? abort(404);
        $data = $request->validate([
            'receipt' => ['nullable', 'array'], 'receipt.*' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'filed_on' => ['nullable', 'array'], 'filed_on.*' => ['nullable', 'date'],
        ], ['receipt.*.regex' => 'A receipt number has letters and digits only.']);

        foreach (range(1, 4) as $q) {
            $receipt = trim((string) ($data['receipt'][$q] ?? ''));
            $existing = TdsReturn::query()->where('financial_year_id', $year->id)->where('quarter', $q)->first();
            if ($receipt === '') {
                $existing?->delete();

                continue;
            }
            TdsReturn::query()->updateOrCreate(['financial_year_id' => $year->id, 'quarter' => $q],
                ['receipt_number' => strtoupper($receipt), 'filed_on' => $data['filed_on'][$q] ?? null]);
        }

        return redirect()->route('form16.index', ['year' => $year->id])->with('success', '24Q receipt numbers saved.');
    }

    protected function year(Request $request): ?FinancialYear
    {
        return $this->form16->year($request->integer('year') ?: null);
    }
}
