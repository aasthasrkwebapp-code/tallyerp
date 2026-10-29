<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Custom roles for the active company. System roles are shown read-only.
 */
class RoleController extends Controller
{
    public function create()
    {
        return view('roles.form', ['role' => new Role, 'permissions' => $this->permissionMatrix(), 'granted' => []]);
    }

    public function store(Request $request, CurrentCompany $current)
    {
        $data = $this->validated($request, $current);

        $role = Role::create([
            'company_id' => $current->id(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);
        $role->permissions()->sync($data['permissions'] ?? []);

        return redirect()->route('members.index')->with('success', "Role \"{$role->name}\" created.");
    }

    public function show(Role $role, CurrentCompany $current)
    {
        $this->ensureVisible($role, $current);

        return view('roles.form', [
            'role' => $role,
            'permissions' => $this->permissionMatrix(),
            'granted' => $role->isSuperRole() ? Permission::pluck('id')->all() : $role->permissions->pluck('id')->all(),
            'readonly' => true,
        ]);
    }

    public function edit(Role $role, CurrentCompany $current)
    {
        $this->ensureEditable($role, $current);

        return view('roles.form', [
            'role' => $role,
            'permissions' => $this->permissionMatrix(),
            'granted' => $role->permissions->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, Role $role, CurrentCompany $current)
    {
        $this->ensureEditable($role, $current);
        $data = $this->validated($request, $current, $role);

        $before = $role->permissionKeys();
        $role->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        $role->permissions()->sync($data['permissions'] ?? []);
        $role->load('permissions');

        \App\Models\AuditLog::record('role_permissions_changed', $role, ['permissions' => $before], ['permissions' => $role->permissionKeys()]);

        return redirect()->route('members.index')->with('success', "Role \"{$role->name}\" updated.");
    }

    public function destroy(Role $role, CurrentCompany $current)
    {
        $this->ensureEditable($role, $current);

        $inUse = \Illuminate\Support\Facades\DB::table('company_user')->where('role_id', $role->id)->exists();
        if ($inUse) {
            return back()->with('error', 'This role is assigned to members. Change their role first.');
        }

        $role->delete();

        return redirect()->route('members.index')->with('success', 'Role deleted.');
    }

    protected function validated(Request $request, CurrentCompany $current, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:50',
                Rule::unique('roles', 'name')->where(fn ($q) => $q->where('company_id', $current->id()))->ignore($role?->id),
                Rule::notIn(array_keys(config('erp.role_grants'))),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', Rule::exists('permissions', 'id')],
        ], ['name.not_in' => 'This name is reserved for a system role.']);
    }

    protected function permissionMatrix()
    {
        return Permission::orderBy('id')->get()->groupBy('module');
    }

    protected function ensureVisible(Role $role, CurrentCompany $current): void
    {
        abort_unless($role->company_id === null || $role->company_id === $current->id(), 404);
    }

    protected function ensureEditable(Role $role, CurrentCompany $current): void
    {
        abort_unless($role->company_id !== null && $role->company_id === $current->id(), 403, 'System roles cannot be changed.');
    }
}
