<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Company;
use App\Support\GstStates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function index(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $accounts = $company->accounts()
            ->with('group')
            ->when($request->filled('group'), fn ($q) => $q->where('account_group_id', $request->integer('group')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->orderBy('name')
            ->get();

        $groups = $company->accountGroups()->orderBy('name')->get();

        return view('accounts.index', compact('company', 'accounts', 'groups'));
    }

    public function create(Company $company)
    {
        $this->authorizeCompany($company);

        return view('accounts.form', [
            'company' => $company,
            'account' => new Account(['opening_balance_type' => 'dr', 'is_active' => true]),
            'groups' => $company->accountGroups()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $company->accounts()->create($this->validated($request, $company));

        return redirect()
            ->route('companies.accounts.index', $company)
            ->with('success', 'Ledger created successfully.');
    }

    public function edit(Company $company, Account $account)
    {
        $this->authorizeCompany($company);

        return view('accounts.form', [
            'company' => $company,
            'account' => $account,
            'groups' => $company->accountGroups()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Company $company, Account $account)
    {
        $this->authorizeCompany($company);

        $account->update($this->validated($request, $company, $account));

        return redirect()
            ->route('companies.accounts.index', $company)
            ->with('success', 'Ledger updated successfully.');
    }

    public function destroy(Company $company, Account $account)
    {
        $this->authorizeCompany($company);

        if ($account->entries()->exists()) {
            return back()->with('error', 'This ledger has vouchers. Mark it inactive instead of deleting it.');
        }

        $account->delete();

        return redirect()
            ->route('companies.accounts.index', $company)
            ->with('success', 'Ledger deleted successfully.');
    }

    protected function validated(Request $request, Company $company, ?Account $account = null): array
    {
        if ($request->filled('pan')) {
            $request->merge(['pan' => strtoupper(trim($request->input('pan')))]);
        }

        if ($request->filled('gstin')) {
            $request->merge(['gstin' => strtoupper(trim($request->input('gstin')))]);
        }

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('chart_of_accounts', 'name')
                    ->where('company_id', $company->id)
                    ->ignore($account?->id),
            ],
            'code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('chart_of_accounts', 'code')
                    ->where('company_id', $company->id)
                    ->ignore($account?->id),
            ],
            'account_group_id' => [
                'required',
                Rule::exists('account_groups', 'id')->where('company_id', $company->id),
            ],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'opening_balance_type' => ['required', 'in:dr,cr'],
            'is_active' => ['sometimes', 'boolean'],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[0-9A-Z]{13}$/'],
            'state_code' => ['nullable', Rule::in(array_keys(GstStates::STATES))],
            'hsn_sac' => ['nullable', 'string', 'max:10', 'regex:/^[0-9]+$/'],
            'gst_rate' => ['nullable', Rule::in(GstInvoiceController::RATES)],
            'pan' => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'tds_section' => ['nullable', Rule::in(array_keys(\App\Support\Tds::SECTIONS))],
        ]);

        // A party's state comes from its GSTIN when one is given.
        $data['state_code'] = GstStates::codeFor($data['gstin'] ?? null) ?? ($data['state_code'] ?? null);

        $data['opening_balance'] = $data['opening_balance'] ?? 0;
        $data['is_active'] = $request->boolean('is_active');
        $data['itc_blocked'] = $request->boolean('itc_blocked');
        $data['account_type'] = AccountGroup::find($data['account_group_id'])->nature;

        return $data;
    }
}
