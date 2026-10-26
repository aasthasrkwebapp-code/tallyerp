<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureCompanySelected;
use App\Http\Requests\CompanyRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Services\CompanySetupService;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $companies = $request->user()->companies()
            ->orderBy('name')
            ->get();

        $roles = \App\Models\Role::whereIn('id', $companies->pluck('pivot.role_id'))->pluck('name', 'id');

        return view('companies.index', [
            'companies' => $companies,
            'roles' => $roles,
            'activeId' => $request->session()->get(EnsureCompanySelected::SESSION_KEY),
        ]);
    }

    public function create()
    {
        return view('companies.create', ['company' => new Company(['country' => 'India', 'currency' => 'INR'])]);
    }

    public function store(CompanyRequest $request, CompanySetupService $setup)
    {
        $company = $setup->create($request->user(), $request->validated());

        $request->session()->put(EnsureCompanySelected::SESSION_KEY, $company->id);

        return redirect()
            ->route('dashboard')
            ->with('success', "Company \"{$company->name}\" created with default groups, ledgers and voucher types.");
    }

    public function show(Request $request, Company $company)
    {
        $this->authorizeMember($request, $company, 'company.view');

        return view('companies.show', compact('company'));
    }

    public function edit(Request $request, Company $company)
    {
        $this->authorizeMember($request, $company, 'company.edit');

        return view('companies.edit', compact('company'));
    }

    public function update(CompanyRequest $request, Company $company)
    {
        $this->authorizeMember($request, $company, 'company.edit');

        $company->update(collect($request->validated())->except(['financial_year'])->all());

        return redirect()
            ->route('companies.show', $company)
            ->with('success', 'Company updated successfully.');
    }

    public function destroy(Request $request, Company $company)
    {
        $this->authorizeMember($request, $company, 'company.delete');

        $request->validate([
            'confirm_name' => ['required', 'in:'.$company->name],
        ], ['confirm_name.in' => 'Type the company name exactly to confirm deletion.']);

        AuditLog::record('company_deleted', $company, $company->only(['name', 'gstin']), null, 'Company deleted: '.$company->name);
        $company->delete();

        if ($request->session()->get(EnsureCompanySelected::SESSION_KEY) === $company->id) {
            $request->session()->forget(EnsureCompanySelected::SESSION_KEY);
        }

        return redirect()
            ->route('companies.index')
            ->with('success', 'Company deleted successfully.');
    }

    public function switch(Request $request, Company $company)
    {
        abort_unless($request->user()->belongsToCompany($company), 403);

        $request->session()->put(EnsureCompanySelected::SESSION_KEY, $company->id);

        return redirect()->route('dashboard')->with('success', "Switched to {$company->name}.");
    }

    /** Checks membership and permission against the given company (not only the active one). */
    protected function authorizeMember(Request $request, Company $company, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $company), 403);
        app(CurrentCompany::class)->set($company);
    }
}
