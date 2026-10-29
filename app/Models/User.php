<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'role_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /** @var array<int, Role|null> role per company id, cached for the request */
    protected array $companyRoles = [];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'shortcuts' => 'array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** True once the user has confirmed an authenticator app. */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /**
     * Check an authenticator code, refusing one already used.
     * Updates the last used time step on success.
     */
    public function verifyTwoFactorCode(string $code): bool
    {
        $step = \App\Support\Totp::verify((string) $this->two_factor_secret, $code, $this->two_factor_last_step);
        if ($step === null) {
            return false;
        }
        $this->forceFill(['two_factor_last_step' => $step])->save();

        return true;
    }

    /** Use up a recovery code; each works once. Codes are kept hashed. */
    public function useRecoveryCode(string $code): bool
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        $codes = $this->two_factor_recovery_codes ?? [];
        foreach ($codes as $i => $hash) {
            if ($code !== '' && \Illuminate\Support\Facades\Hash::check($code, $hash)) {
                unset($codes[$i]);
                $this->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** Remaining unused recovery codes. */
    public function recoveryCodesLeft(): int
    {
        return count($this->two_factor_recovery_codes ?? []);
    }

    /**
     * Email verification can be switched off for local installs without a
     * mail server (ERP_REQUIRE_EMAIL_VERIFICATION=false).
     */
    public function hasVerifiedEmail(): bool
    {
        return ! config('erp.require_email_verification') || ! is_null($this->email_verified_at);
    }

    /** Companies this user is a member of (any role). */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->withPivot(['role_id', 'is_active'])
            ->withTimestamps();
    }

    /** Companies this user created. */
    public function ownedCompanies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function roleFor(?Company $company): ?Role
    {
        if (! $company) {
            return null;
        }

        if (! array_key_exists($company->id, $this->companyRoles)) {
            $membership = $this->companies()
                ->where('companies.id', $company->id)
                ->wherePivot('is_active', true)
                ->first();

            $this->companyRoles[$company->id] = $membership
                ? Role::with('permissions')->find($membership->pivot->role_id)
                : null;
        }

        return $this->companyRoles[$company->id];
    }

    public function belongsToCompany(Company $company): bool
    {
        return $this->roleFor($company) !== null;
    }

    public function hasPermission(string $key, ?Company $company): bool
    {
        $role = $this->roleFor($company);

        if (! $role) {
            return false;
        }

        return $role->isSuperRole() || in_array($key, $role->permissionKeys(), true);
    }

    public function forgetCompanyRoles(): void
    {
        $this->companyRoles = [];
    }
}
