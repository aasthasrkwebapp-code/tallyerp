<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    public function index()
    {
        $companies = Company::accessibleBy(auth()->user())
            ->latest()
            ->get();

        return view('companies.index', compact('companies'));
    }

    public function create()
    {
        return view('companies.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        auth()->user()->companies()->create($data);

        return redirect()
            ->route('companies.index')
            ->with('success', 'Company created successfully.');
    }

    public function show(Company $company)
    {
        $this->authorizeCompany($company);

        $figures = $company->userCan(auth()->user(), 'reports')
            ? \App\Support\Dashboard::figures($company)
            : null;

        return view('companies.show', compact('company', 'figures'));
    }

    public function edit(Company $company)
    {
        $this->authorizeCompany($company);

        return view('companies.edit', compact('company'));
    }

    public function update(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $data = $request->validate($this->rules($company->id));
        $startYear = (int) substr($data['financial_year'], 0, 4);

        if ($company->vouchers()->exists()) {
            // Books with entries keep their start year; earlier years can't be opened.
            if ($startYear < $company->books_start_year) {
                throw ValidationException::withMessages([
                    'financial_year' => 'The books start in '.$company->books_start_year.'. Choose that year or later.',
                ]);
            }
        } else {
            // No entries yet: the books simply start in the chosen year.
            $company->books_start_year = $startYear;
        }

        $company->update($data);

        return redirect()
            ->route('companies.index')
            ->with('success', 'Company updated successfully.');
    }

    /**
     * Move the company to another financial year. Asset and liability balances
     * carry forward; income and expenses start again from zero.
     */
    public function changeYear(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:'.$company->books_start_year, 'max:'.($company->books_start_year + 50)],
        ], [
            'year.min' => 'The books start in '.$company->books_start_year.'.',
        ]);

        $year = (int) $data['year'];
        $company->update(['financial_year' => sprintf('%d-%02d', $year, ($year + 1) % 100)]);

        return back()->with('success', 'Now working in financial year '.$company->financial_year.'.');
    }

    public function destroy(Company $company)
    {
        $this->authorizeCompany($company);

        DB::transaction(fn () => $company->delete());

        return redirect()
            ->route('companies.index')
            ->with('success', 'Company deleted successfully.');
    }

    protected function rules(?int $companyId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'gstin' => [
                'nullable',
                'string',
                'size:15',
                'regex:/^[0-9A-Z]{15}$/',
                Rule::unique('companies', 'gstin')
                    ->where('user_id', auth()->id())
                    ->ignore($companyId),
            ],
            'pan' => [
                'nullable',
                'string',
                'size:10',
                'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/',
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:2000'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'digits:6'],
            'financial_year' => [
                'required',
                'regex:/^\d{4}-\d{2}$/',
                function (string $attribute, mixed $value, \Closure $fail) {
                    // "2026-27": the second part must be the following year.
                    if (is_string($value) && preg_match('/^(\d{4})-(\d{2})$/', $value, $m) && (int) $m[2] !== ((int) $m[1] + 1) % 100) {
                        $fail('The financial year must look like 2026-27.');
                    }
                },
            ],
            'currency' => ['required', 'in:INR'],
        ];
    }
}
