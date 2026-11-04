<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Share a company with other registered users and set their roles.
 */
class CompanyMemberController extends Controller
{
    public function index(Company $company)
    {
        $this->authorizeCompany($company);

        return view('members.index', [
            'company' => $company,
            'members' => $company->members()->orderBy('name')->get(),
            'roles' => $this->assignableRoles(),
            'roleNames' => Role::pluck('name', 'id'),
        ]);
    }

    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'role_id' => ['required', Rule::in($this->assignableRoles()->pluck('id'))],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user) {
            throw ValidationException::withMessages(['email' => 'No account uses this email. Ask them to register first.']);
        }
        if ((int) $user->id === (int) $company->user_id) {
            throw ValidationException::withMessages(['email' => 'This user already owns the company.']);
        }
        if ($company->members()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages(['email' => $user->name.' already has access.']);
        }

        $company->members()->attach($user->id, ['role_id' => $data['role_id']]);
        $this->log($company, 'created', $user, 'Gave '.$user->name.' access as '.Role::find($data['role_id'])->name);

        return back()->with('success', $user->name.' can now access '.$company->name.'.');
    }

    public function update(Request $request, Company $company, User $member)
    {
        $this->authorizeCompany($company);
        abort_unless($company->members()->whereKey($member->id)->exists(), 404);

        $data = $request->validate([
            'role_id' => ['required', Rule::in($this->assignableRoles()->pluck('id'))],
        ]);

        $company->members()->updateExistingPivot($member->id, ['role_id' => $data['role_id']]);
        $this->log($company, 'updated', $member, 'Changed role of '.$member->name.' to '.Role::find($data['role_id'])->name);

        return back()->with('success', 'Role updated for '.$member->name.'.');
    }

    public function destroy(Company $company, User $member)
    {
        $this->authorizeCompany($company);
        abort_unless($company->members()->whereKey($member->id)->exists(), 404);

        $company->members()->detach($member->id);
        $this->log($company, 'deleted', $member, 'Removed access for '.$member->name);

        return back()->with('success', $member->name.' no longer has access.');
    }

    protected function log(Company $company, string $action, User $member, string $summary): void
    {
        AuditLog::create([
            'company_id' => $company->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => 'Member',
            'subject_id' => $member->id,
            'summary' => $summary,
        ]);
    }

    /**
     * Every role except owner (there is exactly one owner: whoever created the company).
     */
    protected function assignableRoles()
    {
        return Role::whereIn('name', array_keys(Access::ROLE_MODULES))
            ->where('name', '!=', 'owner')
            ->orderBy('name')
            ->get();
    }
}
