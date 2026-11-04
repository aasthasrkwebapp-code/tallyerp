<?php

namespace App\Support;

/**
 * Which modules each role may use. The company owner and admins can do everything.
 */
class Access
{
    public const ALL = '*';

    public const ROLE_MODULES = [
        'owner' => [self::ALL],
        'admin' => [self::ALL],
        'accountant' => ['masters', 'vouchers', 'invoices', 'inventory', 'banking', 'reports', 'tds'],
        'sales' => ['invoices', 'reports'],
        'purchases' => ['invoices', 'reports'],
        'inventory' => ['inventory', 'reports'],
        'employee' => ['payroll'],
        'viewer' => ['reports', 'masters', 'vouchers', 'invoices', 'inventory', 'banking', 'tds', 'payroll'],
        'user' => ['reports'],
    ];

    /** Roles that may only look, never change anything. */
    public const READ_ONLY = ['viewer', 'user'];

    /**
     * Route name (after "companies.") → module. First match wins; null = any member.
     */
    public const ROUTE_MODULES = [
        'show' => null,
        'members.*' => 'admin',
        'audit' => 'admin',
        'backup' => 'admin',
        'edit' => 'admin',
        'update' => 'admin',
        'destroy' => 'owner',
        'financial-year' => 'admin',
        'account-groups.*' => 'masters',
        'accounts.*' => 'masters',
        'cost-centres.index' => 'reports',
        'budgets.index' => 'reports',
        'budgets.*' => 'masters',
        'currencies.*' => 'masters',
        'forex.revaluation' => 'reports',
        'forex.*' => 'vouchers',
        'cost-centres.*' => 'masters',
        'vouchers.*' => 'vouchers',
        'gst-invoices.*' => 'invoices',
        'documents.*' => 'invoices',
        'stock-items.*' => 'inventory',
        'inventory.summary' => 'reports',
        'inventory.batches' => 'reports',
        'inventory.*' => 'inventory',
        'banking.*' => 'banking',
        'tds.*' => 'tds',
        'employees.*' => 'payroll',
        'payroll.*' => 'payroll',
        'reports.*' => 'reports',
        'outstanding' => 'reports',
        'gst.*' => 'reports',
    ];

    /**
     * Opening a create or edit form counts as writing, so read-only roles don't see it.
     */
    public static function isWriting(\Illuminate\Http\Request $request): bool
    {
        $name = (string) $request->route()?->getName();

        return ! $request->isMethod('GET') && ! $request->isMethod('HEAD')
            || str_ends_with($name, '.create')
            || str_ends_with($name, '.edit');
    }

    public static function moduleForRoute(?string $routeName): ?string
    {
        $name = str_starts_with((string) $routeName, 'companies.') ? substr($routeName, 10) : (string) $routeName;

        foreach (self::ROUTE_MODULES as $pattern => $module) {
            if (\Illuminate\Support\Str::is($pattern, $name)) {
                return $module;
            }
        }

        return 'admin';
    }

    public static function allows(string $role, ?string $module, bool $writing): bool
    {
        if ($module === null) {
            return true;
        }

        // Only the owner can delete the company.
        if ($module === 'owner') {
            return $role === 'owner';
        }

        $modules = self::ROLE_MODULES[$role] ?? [];

        if (in_array(self::ALL, $modules, true)) {
            return true;
        }

        if ($writing && in_array($role, self::READ_ONLY, true)) {
            return false;
        }

        return in_array($module, $modules, true);
    }
}
