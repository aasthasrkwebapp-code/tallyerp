<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentCompany::class);
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // Permission keys ("vouchers.create") are checked against the user's
        // role in the active company. Other abilities fall through to policies.
        Gate::before(function (User $user, string $ability) {
            if (! array_key_exists(strtok($ability, '.'), config('erp.modules')) || ! str_contains($ability, '.')) {
                return null;
            }

            return $user->hasPermission($ability, app(CurrentCompany::class)->get());
        });

        Event::listen(Login::class, fn (Login $e) => AuditLog::record('login', null, null, null, 'Signed in: '.$e->user->email));
        Event::listen(Logout::class, fn (Logout $e) => $e->user && AuditLog::record('logout', null, null, null, 'Signed out: '.$e->user->email));
        Event::listen(Failed::class, fn (Failed $e) => AuditLog::record('login_failed', null, null, null, 'Failed sign-in for: '.($e->credentials['email'] ?? 'unknown')));
        Event::listen(PasswordReset::class, fn (PasswordReset $e) => AuditLog::record('password_reset', null, null, null, 'Password reset: '.$e->user->email));
    }
}
