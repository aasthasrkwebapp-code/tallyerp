<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeePayHead;
use App\Models\PayHead;
use App\Models\Payslip;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'inactive' => ['nullable', 'boolean'],
        ]);

        $employees = Employee::query()->with('payHeads.payHead')
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%")->orWhere('designation', 'like', "%{$v}%")))
            ->when($filters['department'] ?? null, fn ($q, $v) => $q->where('department', $v))
            ->when(! ($filters['inactive'] ?? false), fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->paginate(50)->withQueryString();

        return view('payroll.employees.index', [
            'employees' => $employees,
            'filters' => $filters,
            'departments' => Employee::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department'),
        ]);
    }

    public function create()
    {
        $next = Employee::query()->pluck('code')->map(fn ($c) => (int) preg_replace('/\D/', '', $c))->max() + 1;
        $employee = new Employee([
            'code' => 'EMP'.str_pad((string) $next, 3, '0', STR_PAD_LEFT),
            'date_of_joining' => now()->toDateString(),
            'pf_applicable' => true, 'esi_applicable' => true, 'pt_applicable' => true, 'is_active' => true,
        ]);

        return view('payroll.employees.form', $this->formData($employee));
    }

    public function store(Request $request, CurrentCompany $current)
    {
        [$data, $structure] = $this->validated($request, $current);
        $employee = DB::transaction(function () use ($data, $structure) {
            $employee = Employee::create($data);
            $this->saveStructure($employee, $structure);

            return $employee;
        });

        return redirect()->route('employees.show', $employee)->with('success', "Employee {$employee->name} added.");
    }

    public function show(Employee $employee)
    {
        $employee->load('payHeads.payHead');

        return view('payroll.employees.show', [
            'employee' => $employee,
            'loans' => app(\App\Services\Payroll\LoanService::class)->summary($employee->loans()->where('status', 'active')->orderBy('date')->get()),
            'payslips' => Payslip::query()->with('run')->where('employee_id', $employee->id)
                ->whereHas('run', fn ($q) => $q->where('status', '!=', 'cancelled'))
                ->get()->sortByDesc(fn ($s) => $s->run->month)->values(),
        ]);
    }

    public function edit(Employee $employee)
    {
        return view('payroll.employees.form', $this->formData($employee->load('payHeads')));
    }

    public function update(Request $request, CurrentCompany $current, Employee $employee)
    {
        [$data, $structure] = $this->validated($request, $current, $employee);
        DB::transaction(function () use ($employee, $data, $structure) {
            $employee->update($data);
            $this->saveStructure($employee, $structure);
        });

        return redirect()->route('employees.show', $employee)->with('success', "Employee {$employee->name} updated.");
    }

    public function destroy(Employee $employee)
    {
        if (Payslip::query()->where('employee_id', $employee->id)->exists() || $employee->loans()->exists() || $employee->leaveEncashments()->exists()) {
            return back()->with('error', 'This employee has payslips, loans or leave encashments and cannot be deleted. Enter a leaving date or mark them inactive instead.');
        }
        $employee->delete();

        return redirect()->route('employees.index')->with('success', "Employee {$employee->name} deleted.");
    }

    protected function formData(Employee $employee): array
    {
        return [
            'employee' => $employee,
            // Loan recovery is automatic, from the employee's loans.
            'heads' => PayHead::query()->where('is_active', true)->where('calculation', '!=', 'loan')->orderBy('sort_order')->orderBy('id')->get(),
            'structure' => $employee->exists ? $employee->payHeads->keyBy('pay_head_id') : collect(),
        ];
    }

    /** @return array{0: array, 1: array<int, ?string>} employee attributes and pay head id => amount */
    protected function validated(Request $request, CurrentCompany $current, ?Employee $employee = null): array
    {
        $request->merge([
            'pan' => $request->pan ? strtoupper(trim($request->pan)) : null,
            'ifsc' => $request->ifsc ? strtoupper(trim($request->ifsc)) : null,
        ]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('employees')->where('company_id', $current->id())->ignore($employee?->id)],
            'name' => ['required', 'string', 'max:150'],
            'designation' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'date_of_joining' => ['required', 'date'],
            'date_of_leaving' => ['nullable', 'date', 'after_or_equal:date_of_joining'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'in:male,female,other'],
            'pan' => ['nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'uan' => ['nullable', 'digits:12'],
            'esi_number' => ['nullable', 'digits_between:10,17'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account' => ['nullable', 'regex:/^[0-9]{6,20}$/'],
            'ifsc' => ['nullable', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'heads' => ['nullable', 'array'],
            'heads.*.on' => ['nullable', 'boolean'],
            'heads.*.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
        ], [
            'pan.regex' => 'Enter a valid PAN, e.g. ABCDE1234F.',
            'ifsc.regex' => 'Enter a valid IFSC, e.g. HDFC0001234.',
            'bank_account.regex' => 'The bank account number must be 6 to 20 digits.',
        ]);

        $heads = PayHead::query()->where('is_active', true)->where('calculation', '!=', 'loan')->get()->keyBy('id');
        $structure = [];
        $errors = [];
        foreach ($request->input('heads', []) as $id => $row) {
            $head = $heads->get((int) $id);
            if (! $head || empty($row['on'])) {
                continue;
            }
            $amount = isset($row['amount']) && $row['amount'] !== '' ? Money::normalize($row['amount']) : null;
            if ($head->calculation === 'flat' && $head->type !== 'employer' && $amount === null) {
                $errors["heads.$id.amount"] = "Enter the monthly amount for {$head->name}.";
            }
            if ($head->calculation === 'overtime' && $amount === null) {
                $errors["heads.$id.amount"] = "Enter the hourly rate for {$head->name}.";
            }
            $structure[$head->id] = in_array($head->calculation, ['flat', 'overtime'], true) ? $amount : null;
        }
        if ($structure && ! $heads->filter(fn ($h) => $h->is_basic)->keys()->intersect(array_keys($structure))->count()) {
            $errors['heads'] = 'Include the basic salary in the salary structure.';
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        unset($data['heads']);
        foreach (['pf_applicable', 'esi_applicable', 'pt_applicable', 'is_active'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        return [$data, $structure];
    }

    protected function saveStructure(Employee $employee, array $structure): void
    {
        $employee->payHeads()->whereNotIn('pay_head_id', array_keys($structure) ?: [0])->delete();
        foreach ($structure as $headId => $amount) {
            EmployeePayHead::query()->updateOrCreate(
                ['employee_id' => $employee->id, 'pay_head_id' => $headId],
                ['company_id' => $employee->company_id, 'amount' => $amount],
            );
        }
    }
}
