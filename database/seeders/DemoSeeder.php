<?php

namespace Database\Seeders;

use App\Http\Controllers\GstInvoiceController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * A demo company with a few months of activity, for trying the app.
 *   php artisan db:seed --class=DemoSeeder
 * Log in as demo@example.com / password.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesSeeder::class);

        $user = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo User', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );
        Auth::login($user); // so the audit log shows who did it

        $year = now()->month >= 4 ? now()->year : now()->year - 1;
        $company = $user->companies()->create([
            'name' => 'Demo Traders', 'legal_name' => 'Demo Traders Pvt Ltd', 'gstin' => '27AAACD1234E1Z5',
            'pan' => 'AAACD1234E', 'address' => '12 MG Road', 'city' => 'Pune', 'pincode' => '411001', 'state' => 'Maharashtra',
            'financial_year' => sprintf('%d-%02d', $year, ($year + 1) % 100), 'currency' => 'INR',
        ]);

        $ledger = function (string $name, string $code, array $extra = []) use ($company) {
            $group = $company->accountGroups()->where('code', $code)->firstOrFail();

            return $company->accounts()->create($extra + ['name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature]);
        };

        $cash = $ledger('Cash', 'CASH_IN_HAND', ['opening_balance' => 25000]);
        $bank = $ledger('HDFC Bank', 'BANK_ACCOUNTS', ['opening_balance' => 300000]);
        $ledger('Capital', 'CAPITAL_ACCOUNT', ['opening_balance' => 375000, 'opening_balance_type' => 'cr']);
        $sales = $ledger('Sales', 'SALES_ACCOUNTS', ['hsn_sac' => '8471', 'gst_rate' => 18]);
        $purchases = $ledger('Purchases', 'PURCHASE_ACCOUNTS');
        $rent = $ledger('Rent', 'INDIRECT_EXPENSES');
        $electricity = $ledger('Electricity', 'INDIRECT_EXPENSES');
        $local = $ledger('Pune Stores', 'SUNDRY_DEBTORS', ['gstin' => '27AABCP5678F1Z2', 'state_code' => '27', 'address' => 'FC Road', 'city' => 'Pune', 'pincode' => '411004']);
        $outstation = $ledger('Bengaluru Tech Pvt Ltd', 'SUNDRY_DEBTORS', ['gstin' => '29AABCB9012G1Z8', 'state_code' => '29', 'address' => 'Residency Road', 'city' => 'Bengaluru', 'pincode' => '560025']);
        $supplier = $ledger('Mumbai Wholesale', 'SUNDRY_CREDITORS', ['gstin' => '27AABCM3456H1Z4', 'state_code' => '27']);
        $ledger('Ravi Contractors', 'SUNDRY_CREDITORS', ['pan' => 'ABCPR1234K', 'tds_section' => '194C-O']);

        $laptop = $company->stockItems()->create([
            'name' => 'Laptop', 'unit_id' => $company->units()->where('symbol', 'Nos')->value('id'),
            'hsn_sac' => '8471', 'gst_rate' => 18, 'opening_quantity' => 20, 'opening_rate' => 40000,
        ]);
        $capital = $company->accounts()->where('name', 'Capital')->first();
        $capital->update(['opening_balance' => 375000 + 800000]); // stock funded by capital

        $date = fn (int $monthOffset, int $day) => now()->setDate($year, 4, 1)->addMonths($monthOffset)->setDay($day)->toDateString();

        foreach ([[0, $local, 3, 52000], [1, $outstation, 2, 54000], [2, $local, 4, 51000]] as [$m, $party, $qty, $price]) {
            $this->post($company, 'gst-invoices.store', [
                'type' => 'sales', 'date' => $date($m, 10), 'party_account_id' => $party->id,
                'lines' => [['account_id' => $sales->id, 'stock_item_id' => $laptop->id, 'quantity' => $qty, 'item_rate' => $price, 'hsn_sac' => '8471', 'rate' => 18]],
            ]);
        }
        $this->post($company, 'gst-invoices.store', [
            'type' => 'purchase', 'date' => $date(1, 5), 'party_account_id' => $supplier->id, 'reference' => 'MW/221',
            'lines' => [['account_id' => $purchases->id, 'stock_item_id' => $laptop->id, 'quantity' => 5, 'item_rate' => 41000, 'hsn_sac' => '8471', 'rate' => 18]],
        ]);
        foreach (range(0, 2) as $m) {
            $this->post($company, 'vouchers.store', ['type' => 'payment', 'date' => $date($m, 5), 'narration' => 'Office rent', 'entries' => [
                ['account_id' => $rent->id, 'debit' => 30000], ['account_id' => $bank->id, 'credit' => 30000],
            ]]);
            $this->post($company, 'vouchers.store', ['type' => 'payment', 'date' => $date($m, 20), 'narration' => 'Electricity bill', 'entries' => [
                ['account_id' => $electricity->id, 'debit' => 4200 + $m * 300], ['account_id' => $cash->id, 'credit' => 4200 + $m * 300],
            ]]);
        }
        $this->post($company, 'vouchers.store', ['type' => 'receipt', 'date' => $date(1, 2), 'narration' => 'Payment from Pune Stores', 'entries' => [
            ['account_id' => $bank->id, 'debit' => 150000], ['account_id' => $local->id, 'credit' => 150000],
        ]]);

        $this->command?->info('Demo company ready. Log in as demo@example.com / password.');
    }

    /**
     * Post through the app's own controllers so every rule applies.
     */
    protected function post(Company $company, string $route, array $data): void
    {
        $controller = [
            'gst-invoices.store' => \App\Http\Controllers\GstInvoiceController::class,
            'vouchers.store' => \App\Http\Controllers\VoucherController::class,
        ][$route];

        $request = \Illuminate\Http\Request::create('/', 'POST', $data);
        $request->setUserResolver(fn () => Auth::user());
        app()->instance('request', $request);

        // Validation errors surface as exceptions, so bad demo data fails loudly.
        app($controller)->store($request, $company);
    }
}
