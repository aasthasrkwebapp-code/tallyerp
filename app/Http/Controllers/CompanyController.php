<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function index()
    {
        $companies = auth()->user()
            ->companies()
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

        return view('companies.show', compact('company'));
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

        $company->update($data);

        return redirect()
            ->route('companies.index')
            ->with('success', 'Company updated successfully.');
    }

    public function destroy(Company $company)
    {
        $this->authorizeCompany($company);

        $company->delete();

        return redirect()
            ->route('companies.index')
            ->with('success', 'Company deleted successfully.');
    }

    protected function authorizeCompany(Company $company): void
    {
        if ($company->user_id !== auth()->id()) {
            abort(403);
        }
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
                Rule::unique('companies', 'gstin')->ignore($companyId),
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
            'financial_year' => [
                'required',
                'regex:/^\d{4}-\d{2}$/',
            ],
            'currency' => ['required', 'in:INR'],
        ];
    }
}
