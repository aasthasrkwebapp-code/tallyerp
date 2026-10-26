<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanySetupService;
use Illuminate\Database\Seeder;

/**
 * Local demo data: a verified demo user who owns a sample company.
 * Login: demo@example.com / password
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo User', 'password' => 'password', 'email_verified_at' => now()]
        );

        if (Company::where('name', 'Demo Traders')->exists()) {
            return;
        }

        app(CompanySetupService::class)->create($user, [
            'name' => 'Demo Traders',
            'legal_name' => 'Demo Traders Private Limited',
            'gstin' => '27AAACD1234E1Z5',
            'pan' => 'AAACD1234E',
            'state' => 'Maharashtra',
            'city' => 'Pune',
            'pincode' => '411001',
            'email' => 'accounts@demotraders.test',
            'financial_year' => (now()->month >= 4 ? now()->year : now()->year - 1).'-'.substr((string) (now()->month >= 4 ? now()->year + 1 : now()->year), -2),
            'currency' => 'INR',
        ]);
    }
}
