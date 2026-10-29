<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Users who can access the active company, and the role each one holds.
 */
class MemberController extends Controller
{
    public function index(CurrentCompany $current)
    {
        $company = $current->get();

        return view('members.index', [
            'members' => $company->members()->orderBy('name')->get(),
            'roles' => Role::availableTo($company)->with('permissions')->orderByDesc('is_system')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, CurrentCompany $current)
    {
        $company = $current->get();

        $data = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')],
            'role_id' => ['required', Rule::exists('roles', 'id')->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))],
        ], ['email.exists' => 'No registered user has this email. Ask them to register first.']);

        $user = User::where('email', $data['email'])->firstOrFail();

        if ($company->members()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This user is already a member of the company.']);
        }

        $company->members()->attach($user->id, ['role_id' => $data['role_id'], 'is_active' => true]);
        AuditLog::record('member_added', $user, null, ['role_id' => (int) $data['role_id']], "Member added: {$user->email}", $company->id);

        return back()->with('success', "{$user->name} can now access {$company->name}.");
    }

    public function update(Request $request, User $user, CurrentCompany $current)
    {
        $company = $current->get();
        $membership = $company->members()->whereKey($user->id)->firstOrFail()->pivot;

        $data = $request->validate([
            'role_id' => ['required', Rule::exists('roles', 'id')->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        $this->guardLastOwner($company, $user, (int) $data['role_id'], $data['is_active']);

        $company->members()->updateExistingPivot($user->id, $data);
        AuditLog::record('member_updated', $user, ['role_id' => $membership->role_id, 'is_active' => (bool) $membership->is_active], $data, "Member updated: {$user->email}", $company->id);

        return back()->with('success', "Access for {$user->name} updated.");
    }

    public function destroy(User $user, CurrentCompany $current)
    {
        $company = $current->get();
        $company->members()->whereKey($user->id)->firstOrFail();

        $this->guardLastOwner($company, $user, null, false);

        $company->members()->detach($user->id);
        AuditLog::record('member_removed', $user, null, null, "Member removed: {$user->email}", $company->id);

        return back()->with('success', "{$user->name} no longer has access.");
    }

    /**
     * Turn off a member's two-factor sign-in (lost phone and no recovery
     * codes). Only an owner or admin may do this, and only for someone whose
     * every company they also own or administer, as the sign-in is shared.
     */
    public function resetTwoFactor(Request $request, User $user, CurrentCompany $current)
    {
        $company = $current->get();
        $company->members()->whereKey($user->id)->firstOrFail();
        $actor = $request->user();
        $super = config('erp.super_roles');
        abort_unless(in_array($actor->roleFor($company)?->name, $super, true), 403, 'Only an owner or admin can reset two-factor sign-in.');
        abort_if($actor->id === $user->id, 403, 'Turn off your own two-factor sign-in from your profile.');
        $outside = $user->companies()->get()->reject(fn ($c) => in_array($actor->roleFor($c)?->name, $super, true));
        if ($outside->isNotEmpty()) {
            return back()->with('error', "{$user->name} also uses other companies you do not manage, so only they (or an owner of those companies) can reset it.");
        }
        $request->validate(['password' => ['required', 'current_password']], ['password.current_password' => 'Your password is incorrect.']);

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
        AuditLog::record('two_factor_reset', $user, null, null, "Two-factor sign-in reset for {$user->email} by {$actor->email}", $company->id);

        return back()->with('success', "Two-factor sign-in for {$user->name} is off. They can set it up again from their profile.");
    }

    /** A company must always keep at least one active owner. */
    protected function guardLastOwner($company, User $user, ?int $newRoleId, bool $active): void
    {
        $ownerRoleId = Role::whereNull('company_id')->where('name', 'owner')->value('id');
        $current = $company->members()->whereKey($user->id)->first()?->pivot;

        if (! $current || (int) $current->role_id !== (int) $ownerRoleId) {
            return;
        }

        if ($newRoleId === (int) $ownerRoleId && $active) {
            return;
        }

        $otherOwners = $company->members()
            ->wherePivot('role_id', $ownerRoleId)
            ->wherePivot('is_active', true)
            ->where('users.id', '!=', $user->id)
            ->count();

        if ($otherOwners === 0) {
            throw ValidationException::withMessages(['role_id' => 'The company must keep at least one active owner.']);
        }
    }
}
