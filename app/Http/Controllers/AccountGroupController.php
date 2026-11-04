<?php

namespace App\Http\Controllers;

use App\Models\AccountGroup;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountGroupController extends Controller
{
    public function index(Company $company)
    {
        $this->authorizeCompany($company);

        $groups = $company->accountGroups()
            ->with('parent')
            ->withCount('accounts')
            ->orderBy('name')
            ->get();

        return view('account-groups.index', compact('company', 'groups'));
    }

    public function create(Company $company)
    {
        $this->authorizeCompany($company);

        $parents = $company->accountGroups()->orderBy('name')->get();

        return view('account-groups.form', [
            'company' => $company,
            'group' => new AccountGroup,
            'parents' => $parents,
        ]);
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $data = $this->validated($request, $company);
        $company->accountGroups()->create($data);

        return redirect()
            ->route('companies.account-groups.index', $company)
            ->with('success', 'Group created successfully.');
    }

    public function edit(Company $company, AccountGroup $accountGroup)
    {
        $this->authorizeCompany($company);

        $parents = $company->accountGroups()
            ->whereKeyNot($accountGroup->id)
            ->orderBy('name')
            ->get();

        return view('account-groups.form', [
            'company' => $company,
            'group' => $accountGroup,
            'parents' => $parents,
        ]);
    }

    public function update(Request $request, Company $company, AccountGroup $accountGroup)
    {
        $this->authorizeCompany($company);

        $data = $this->validated($request, $company, $accountGroup);
        $accountGroup->update($data);

        return redirect()
            ->route('companies.account-groups.index', $company)
            ->with('success', 'Group updated successfully.');
    }

    public function destroy(Company $company, AccountGroup $accountGroup)
    {
        $this->authorizeCompany($company);

        if ($accountGroup->is_primary || $accountGroup->code) {
            return back()->with('error', 'Predefined groups cannot be deleted.');
        }

        if ($accountGroup->accounts()->exists() || $accountGroup->children()->exists()) {
            return back()->with('error', 'Move its ledgers and sub-groups before deleting this group.');
        }

        $accountGroup->delete();

        return redirect()
            ->route('companies.account-groups.index', $company)
            ->with('success', 'Group deleted successfully.');
    }

    protected function validated(Request $request, Company $company, ?AccountGroup $group = null): array
    {
        $nameRule = [
            'required', 'string', 'max:255',
            Rule::unique('account_groups', 'name')
                ->where('company_id', $company->id)
                ->ignore($group?->id),
        ];

        // Predefined groups keep their place in the hierarchy; only rename.
        if ($group?->code) {
            return $request->validate(['name' => $nameRule]);
        }

        $data = $request->validate([
            'name' => $nameRule,
            'parent_id' => [
                'required',
                Rule::exists('account_groups', 'id')->where('company_id', $company->id),
                Rule::notIn(array_filter([$group?->id])),
            ],
        ]);

        // A sub-group always takes its nature from its parent, as in Tally.
        $data['nature'] = AccountGroup::find($data['parent_id'])->nature;
        $data['is_primary'] = false;

        return $data;
    }
}
