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
        $ledger('Capital', 'CAPITAL_ACCOUNT', ['opening_balance' => 325000, 'opening_balance_type' => 'cr']);
        $sales = $ledger('Sales', 'SALES_ACCOUNTS', ['hsn_sac' => '8471', 'gst_rate' => 18]);
        $purchases = $ledger('Purchases', 'PURCHASE_ACCOUNTS');
        $rent = $ledger('Rent', 'INDIRECT_EXPENSES');
        $electricity = $ledger('Electricity', 'INDIRECT_EXPENSES');
        $local = $ledger('Pune Stores', 'SUNDRY_DEBTORS', ['gstin' => '27AABCP5678F1Z2', 'state_code' => '27', 'address' => 'FC Road', 'city' => 'Pune', 'pincode' => '411004']);
        $outstation = $ledger('Bengaluru Tech Pvt Ltd', 'SUNDRY_DEBTORS', ['gstin' => '29AABCB9012G1Z8', 'state_code' => '29', 'address' => 'Residency Road', 'city' => 'Bengaluru', 'pincode' => '560025']);
        $supplier = $ledger('Mumbai Wholesale', 'SUNDRY_CREDITORS', ['gstin' => '27AABCM3456H1Z4', 'state_code' => '27']);
        $ravi = $ledger('Ravi Contractors', 'SUNDRY_CREDITORS', ['pan' => 'ABCPR1234K', 'tds_section' => '194C-O']);
        $repairs = $ledger('Repairs & Maintenance', 'INDIRECT_EXPENSES');
        $staffWelfare = $ledger('Staff Welfare', 'INDIRECT_EXPENSES', ['itc_blocked' => true]);

        // Cost centres for the two branches.
        $pune = $company->costCentres()->create(['name' => 'Pune Branch']);
        $mumbai = $company->costCentres()->create(['name' => 'Mumbai Branch']);

        $laptop = $company->stockItems()->create([
            'name' => 'Laptop', 'unit_id' => $company->units()->where('symbol', 'Nos')->value('id'),
            'hsn_sac' => '8471', 'gst_rate' => 18, 'opening_quantity' => 20, 'opening_rate' => 40000,
        ]);
        $capital = $company->accounts()->where('name', 'Capital')->first();
        $capital->update(['opening_balance' => 325000 + 800000]); // cash + bank + stock

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
                ['account_id' => $rent->id, 'debit' => 30000, 'cost_centre_id' => $pune->id], ['account_id' => $bank->id, 'credit' => 30000],
            ]]);
            $this->post($company, 'vouchers.store', ['type' => 'payment', 'date' => $date($m, 20), 'narration' => 'Electricity bill', 'entries' => [
                ['account_id' => $electricity->id, 'debit' => 4200 + $m * 300, 'cost_centre_id' => $m === 1 ? $mumbai->id : $pune->id], ['account_id' => $cash->id, 'credit' => 4200 + $m * 300],
            ]]);
        }
        $this->post($company, 'vouchers.store', ['type' => 'receipt', 'date' => $date(1, 2), 'narration' => 'Payment from Pune Stores', 'entries' => [
            ['account_id' => $bank->id, 'debit' => 150000], ['account_id' => $local->id, 'credit' => 150000],
        ]]);

        // A sales return (credit note) for one laptop from the first sale.
        $this->post($company, 'gst-invoices.store', [
            'type' => 'credit_note', 'date' => $date(0, 25), 'party_account_id' => $local->id, 'reference' => '1/'.$company->financial_year,
            'lines' => [['account_id' => $sales->id, 'stock_item_id' => $laptop->id, 'quantity' => 1, 'item_rate' => 52000, 'hsn_sac' => '8471', 'rate' => 18, 'description' => 'Laptop returned']],
        ]);

        // A staff lunch bill: GST credit is blocked, so the tax becomes cost.
        $this->post($company, 'gst-invoices.store', [
            'type' => 'purchase', 'date' => $date(2, 12), 'party_account_id' => $supplier->id, 'reference' => 'MW/305',
            'lines' => [['account_id' => $staffWelfare->id, 'taxable' => 6000, 'hsn_sac' => '996331', 'rate' => 5, 'description' => 'Team lunch']],
        ]);

        // Contractor bill with TDS, then the TDS deposited with the government.
        $this->call_(\App\Http\Controllers\TdsController::class, 'store', $company, [
            'date' => $date(1, 18), 'party_account_id' => $ravi->id, 'expense_account_id' => $repairs->id, 'amount' => 80000, 'reference' => 'RC/17',
        ]);
        $this->post($company, 'vouchers.store', ['type' => 'payment', 'date' => $date(2, 7), 'narration' => 'TDS 194C deposited (challan 28104)', 'entries' => [
            ['account_id' => $company->accounts()->where('code', 'TDS_194C')->value('id'), 'debit' => 1600], ['account_id' => $bank->id, 'credit' => 1600],
        ]]);

        // Employees and two months of payroll.
        $staff = collect([
            ['code' => 'E001', 'name' => 'Asha Patil', 'designation' => 'Accountant', 'basic' => 18000, 'hra' => 7000, 'other_allowances' => 3000, 'pan' => 'ABCPA1111A'],
            ['code' => 'E002', 'name' => 'Rahul Shinde', 'designation' => 'Sales Executive', 'basic' => 11000, 'hra' => 4000, 'other_allowances' => 2500],
            ['code' => 'E003', 'name' => 'Meera Kulkarni', 'designation' => 'Store Manager', 'basic' => 32000, 'hra' => 12000, 'other_allowances' => 4000, 'pan' => 'ABCPK3333C'],
        ])->map(fn ($e) => $company->employees()->create($e + ['joined_on' => $date(0, 1)]));

        foreach ([0 => [30, 30, 30], 1 => [31, 28, 31]] as $m => $days) {
            $this->call_(\App\Http\Controllers\PayrollController::class, 'store', $company, [
                'month' => substr($date($m, 1), 0, 7),
                'days' => $staff->pluck('id')->combine($days)->all(),
            ]);
        }

        // Budgets for the year.
        $this->call_(\App\Http\Controllers\BudgetController::class, 'update', $company, ['budgets' => [
            $sales->id => 2400000, $rent->id => 360000, $electricity->id => 60000, $repairs->id => 100000,
            $company->accounts()->where('code', 'PAY_SALARIES')->value('id') => 1100000,
        ]]);

        // An overseas customer in US dollars: export sale and part payment.
        $company->currencies()->create(['code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar']);
        $exports = $ledger('Export Sales', 'SALES_ACCOUNTS');
        $usCustomer = $ledger('Global Gadgets Inc (USA)', 'SUNDRY_DEBTORS', ['currency_code' => 'USD', 'address' => '500 Market St', 'city' => 'San Francisco']);
        $this->post($company, 'vouchers.store', ['type' => 'journal', 'date' => $date(1, 22), 'narration' => 'Export invoice EXP/1 for $2,000', 'entries' => [
            ['account_id' => $usCustomer->id, 'side' => 'dr', 'foreign_amount' => 2000, 'exchange_rate' => 83.20],
            ['account_id' => $exports->id, 'credit' => 166400],
        ]]);
        $this->post($company, 'vouchers.store', ['type' => 'receipt', 'date' => $date(2, 15), 'narration' => 'Part payment $1,200', 'entries' => [
            ['account_id' => $bank->id, 'debit' => 100560],
            ['account_id' => $usCustomer->id, 'side' => 'cr', 'foreign_amount' => 1200, 'exchange_rate' => 83.80],
        ]]);

        // The bank has cleared everything up to the end of May.
        $bank->entries()->with('voucher')->get()
            ->filter(fn ($e) => $e->voucher->date->lte(now()->setDate($year, 5, 31)))
            ->each(fn ($e) => $e->update(['bank_date' => $e->voucher->date->addDays(2)->toDateString()]));

        // A second user who can only look at the books.
        $viewer = User::firstOrCreate(
            ['email' => 'viewer@example.com'],
            ['name' => 'Ravi Viewer', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );
        $company->members()->syncWithoutDetaching([$viewer->id => ['role_id' => \App\Models\Role::where('name', 'viewer')->value('id')]]);

        $this->command?->info('Demo company ready. Log in as demo@example.com / password (viewer@example.com / password is read-only).');
    }

    /**
     * Call any controller action with form data, as if submitted by the logged-in user.
     */
    protected function call_(string $controller, string $method, Company $company, array $data): void
    {
        $request = \Illuminate\Http\Request::create('/', 'POST', $data);
        $request->setUserResolver(fn () => Auth::user());
        app()->instance('request', $request);

        app($controller)->{$method}($request, $company);
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
