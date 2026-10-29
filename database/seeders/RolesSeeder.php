<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the permission catalogue and the system roles with their default
 * grants from config/erp.php. Safe to run repeatedly.
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        Permission::syncFromConfig();

        $descriptions = [
            'owner' => 'Full access to all modules and settings',
            'admin' => 'Administrative access to all modules',
            'user' => 'Standard user with basic access',
            'accountant' => 'Access to accounting, banking, and reports',
            'sales' => 'Access to sales, invoices, and receivables',
            'purchases' => 'Access to purchases, bills, and payables',
            'inventory' => 'Access to inventory management and stock',
            'employee' => 'Access to payroll and employee records',
            'transport' => 'Access to trips, vehicles and transport expenses',
            'viewer' => 'Read-only access to reports and masters',
        ];

        foreach (config('erp.role_grants') as $name => $grants) {
            $role = Role::query()->whereNull('company_id')->where('name', $name)->first()
                ?? Role::create(['name' => $name]);

            $role->forceFill([
                'description' => $role->description ?: ($descriptions[$name] ?? null),
                'is_system' => true,
            ])->save();

            $ids = Permission::query()->whereIn('key', Permission::expand($grants))->pluck('id');
            $role->permissions()->sync($ids);
        }
    }
}
