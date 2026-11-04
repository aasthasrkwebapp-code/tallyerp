<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Company $company)
    {
        $this->authorizeCompany($company);

        $employees = $company->employees()->orderByDesc('is_active')->orderBy('name')->get();

        return view('employees.index', compact('company', 'employees'));
    }

    public function create(Company $company)
    {
        $this->authorizeCompany($company);

        return view('employees.form', [
            'company' => $company,
            'employee' => new Employee(['pf_applicable' => true, 'esi_applicable' => true, 'pt_applicable' => true, 'is_active' => true]),
        ]);
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $company->employees()->create($this->validated($request, $company));

        return redirect()
            ->route('companies.employees.index', $company)
            ->with('success', 'Employee added.');
    }

    public function edit(Company $company, Employee $employee)
    {
        $this->authorizeCompany($company);

        return view('employees.form', compact('company', 'employee'));
    }

    public function update(Request $request, Company $company, Employee $employee)
    {
        $this->authorizeCompany($company);

        $employee->update($this->validated($request, $company, $employee));

        return redirect()
            ->route('companies.employees.index', $company)
            ->with('success', 'Employee updated.');
    }

    public function destroy(Company $company, Employee $employee)
    {
        $this->authorizeCompany($company);

        if ($employee->payslips()->exists()) {
            return back()->with('error', 'This employee has payslips. Mark them inactive instead.');
        }

        $employee->delete();

        return redirect()
            ->route('companies.employees.index', $company)
            ->with('success', 'Employee deleted.');
    }

    protected function validated(Request $request, Company $company, ?Employee $employee = null): array
    {
        if ($request->filled('pan')) {
            $request->merge(['pan' => strtoupper(trim($request->input('pan')))]);
        }

        $money = ['nullable', 'numeric', 'min:0', 'max:9999999999.99'];

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:30', Rule::unique('employees')->where('company_id', $company->id)->ignore($employee?->id)],
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'pan' => ['nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'uan' => ['nullable', 'string', 'max:20'],
            'joined_on' => ['nullable', 'date'],
            'basic' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'hra' => $money,
            'other_allowances' => $money,
        ]);

        foreach (['hra', 'other_allowances'] as $field) {
            $data[$field] = $data[$field] ?? 0;
        }
        foreach (['pf_applicable', 'esi_applicable', 'pt_applicable', 'is_active'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        return $data;
    }
}
