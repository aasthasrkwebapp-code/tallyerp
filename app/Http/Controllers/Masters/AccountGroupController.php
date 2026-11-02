<?php

namespace App\Http\Controllers\Masters;

use App\Http\Controllers\Controller;
use App\Models\AccountGroup;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountGroupController extends Controller
{
    public function index()
    {
        $groups = AccountGroup::tree();
        $counts = \App\Models\Account::query()->selectRaw('account_group_id, count(*) as c')->groupBy('account_group_id')->pluck('c', 'account_group_id');

        return view('masters.groups.index', compact('groups', 'counts'));
    }

    public function create()
    {
        return view('masters.groups.form', ['group' => new AccountGroup, 'parents' => AccountGroup::tree()]);
    }

    public function store(Request $request, CurrentCompany $current)
    {
        $data = $this->validated($request, $current);
        $group = AccountGroup::create($data);

        return redirect()->route('account-groups.index')->with('success', "Group \"{$group->name}\" created.");
    }

    public function edit(AccountGroup $accountGroup)
    {
        $exclude = $accountGroup->descendantIds();

        return view('masters.groups.form', [
            'group' => $accountGroup,
            'parents' => AccountGroup::tree()->reject(fn ($g) => in_array($g->id, $exclude, true)),
        ]);
    }

    public function update(Request $request, AccountGroup $accountGroup, CurrentCompany $current)
    {
        $data = $this->validated($request, $current, $accountGroup);

        if ($accountGroup->is_system) {
            // Predefined groups can be renamed but keep their place in the structure.
            $data = array_intersect_key($data, array_flip(['name', 'code', 'description']));
        } elseif (! empty($data['parent_id']) && in_array((int) $data['parent_id'], $accountGroup->descendantIds(), true)) {
            throw ValidationException::withMessages(['parent_id' => 'A group cannot be placed under itself or its own sub-group.']);
        }

        $accountGroup->update($data);

        return redirect()->route('account-groups.index')->with('success', "Group \"{$accountGroup->name}\" updated.");
    }

    public function destroy(AccountGroup $accountGroup)
    {
        if ($accountGroup->is_system) {
            return back()->with('error', 'Predefined groups cannot be deleted.');
        }
        if ($accountGroup->children()->exists() || $accountGroup->accounts()->exists()) {
            return back()->with('error', 'Move or delete the sub-groups and ledgers under this group first.');
        }

        $accountGroup->delete();

        return redirect()->route('account-groups.index')->with('success', 'Group deleted.');
    }

    protected function validated(Request $request, CurrentCompany $current, ?AccountGroup $group = null): array
    {
        $companyId = $current->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('account_groups')->where('company_id', $companyId)->ignore($group?->id)],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('account_groups')->where('company_id', $companyId)->ignore($group?->id)],
            'parent_id' => ['nullable', 'integer', Rule::exists('account_groups', 'id')->where('company_id', $companyId)],
            'nature' => ['required_without:parent_id', 'nullable', Rule::in(array_keys(AccountGroup::NATURES))],
            'affects_gross_profit' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ], ['nature.required_without' => 'Choose the nature of a primary group.']);

        $data['affects_gross_profit'] = $request->boolean('affects_gross_profit')
            && in_array($data['nature'] ?? null, ['income', 'expense'], true);
        $data['nature'] ??= 'asset';

        return $data;
    }
}
