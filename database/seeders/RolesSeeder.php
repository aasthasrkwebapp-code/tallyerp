<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'owner', 'description' => 'Full access to all modules and settings'],
            ['name' => 'admin', 'description' => 'Administrative access to all modules'],
            ['name' => 'user', 'description' => 'Standard user with basic access'],
            ['name' => 'accountant', 'description' => 'Access to accounting, banking, and reports'],
            ['name' => 'sales', 'description' => 'Access to sales, invoices, and receivables'],
            ['name' => 'purchases', 'description' => 'Access to purchases, bills, and payables'],
            ['name' => 'inventory', 'description' => 'Access to inventory management and stock'],
            ['name' => 'employee', 'description' => 'Access to payroll and employee records'],
            ['name' => 'viewer', 'description' => 'Read-only access to reports and masters'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role['name']],
                ['description' => $role['description']]
            );
        }
    }
}
