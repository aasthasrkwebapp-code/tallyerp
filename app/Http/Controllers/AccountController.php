<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Company;
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
        ]);

        $data['opening_balance'] = $data['opening_balance'] ?? 0;
        $data['is_active'] = $request->boolean('is_active');
        $data['account_type'] = AccountGroup::find($data['account_group_id'])->nature;

        return $data;
    }
}
