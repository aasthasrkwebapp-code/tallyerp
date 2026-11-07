<?php

namespace Database\Seeders;

use App\Http\Controllers\LoanController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * A large company for load and regression testing: thousands of entries posted
 * through the app's own controllers, so every validation rule runs.
 *   php artisan db:seed --class=BulkDataSeeder          (5000 entries)
 *   BULK_ENTRIES=20000 php artisan db:seed --class=BulkDataSeeder
 * Log in as demo@example.com / password and open "Bulk Test Traders".
 */
class BulkDataSeeder extends DemoSeeder
{
    public int $entries;

    public function __construct()
    {
        $this->entries = (int) env('BULK_ENTRIES', 5000);
    }

    public function run(): void
    {
        $this->call(RolesSeeder::class);
        // One transaction: SQLite otherwise syncs to disk after every insert.
        \Illuminate\Support\Facades\DB::transaction(fn () => $this->seed());
    }

    protected function seed(): void
    {
        mt_srand(2026); // same data every run

        $user = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo User', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );
        Auth::login($user);

        $year = now()->month >= 4 ? now()->year : now()->year - 1;
        $company = $user->companies()->create([
            'name' => 'Bulk Test Traders', 'legal_name' => 'Bulk Test Traders Pvt Ltd', 'gstin' => '27AABCT1234E1Z5',
            'address' => '1 Station Road', 'city' => 'Pune', 'pincode' => '411001', 'state' => 'Maharashtra',
            'financial_year' => sprintf('%d-%02d', $year, ($year + 1) % 100), 'currency' => 'INR',
            'upi_id' => 'bulktest@upi', 'bank_name' => 'HDFC Bank', 'bank_account_no' => '50100012345678', 'bank_ifsc' => 'HDFC0001234',
        ]);

        $ledger = function (string $name, string $code, array $extra = []) use ($company) {
            $group = $company->accountGroups()->where('code', $code)->firstOrFail();

            return $company->accounts()->create($extra + ['name' => $name, 'account_group_id' => $group->id, 'account_type' => $group->nature]);
        };

        $cash = $ledger('Cash', 'CASH_IN_HAND', ['opening_balance' => 500000]);
        $bank = $ledger('HDFC Bank', 'BANK_ACCOUNTS', ['opening_balance' => 50000000]);
        $sbi = $ledger('SBI Current A/c', 'BANK_ACCOUNTS', ['opening_balance' => 10000000]);
        $sales = $ledger('Sales', 'SALES_ACCOUNTS');
        $serviceIncome = $ledger('Service Income', 'SALES_ACCOUNTS');
        $purchases = $ledger('Purchases', 'PURCHASE_ACCOUNTS');
        $expenses = collect(['Rent', 'Electricity', 'Telephone', 'Freight', 'Printing & Stationery', 'Repairs'])
            ->map(fn ($n) => $ledger($n, 'INDIRECT_EXPENSES'));

        $states = ['27' => 'Pune', '29' => 'Bengaluru', '24' => 'Ahmedabad', '07' => 'Delhi', '33' => 'Chennai'];
        $customers = collect(range(1, 60))->map(function ($i) use ($ledger, $states) {
            $state = array_keys($states)[$i % 5];

            return $ledger(sprintf('Customer %02d', $i), 'SUNDRY_DEBTORS', [
                'state_code' => (string) $state, 'city' => $states[$state],
                'gstin' => $i % 3 ? sprintf('%sAABCC%04dA1Z5', $state, $i) : null,
                // The first customer is the owner's own test number.
                'phone' => $i === 1 ? '+919660533307' : '98'.sprintf('%08d', 10000000 + $i * 7919),
                'email' => sprintf('customer%02d@example.com', $i),
            ]);
        });
        $suppliers = collect(range(1, 20))->map(fn ($i) => $ledger(sprintf('Supplier %02d', $i), 'SUNDRY_CREDITORS', [
            'state_code' => '27', 'gstin' => sprintf('27AABCS%04dB1Z5', $i), 'phone' => '97'.sprintf('%08d', 20000000 + $i * 104729),
        ]));

        $unit = $company->units()->where('symbol', 'Nos')->value('id');
        $openingStock = 0;
        $items = collect(range(1, 40))->map(function ($i) use ($company, $unit, &$openingStock) {
            $rate = 100 * (($i % 20) + 1);
            $qty = 100000; // plenty, so sales never run short
            $openingStock += $rate * $qty;

            return $company->stockItems()->create([
                'name' => sprintf('Item %02d', $i), 'unit_id' => $unit, 'hsn_sac' => '8471', 'gst_rate' => [5, 12, 18, 28][$i % 4],
                'opening_quantity' => $qty, 'opening_rate' => $rate, 'selling_price' => round($rate * 1.3, 2),
                'barcode' => sprintf('890%010d', $i),
            ]);
        });
        $ledger('Capital', 'CAPITAL_ACCOUNT', ['opening_balance' => 500000 + 50000000 + 10000000 + $openingStock, 'opening_balance_type' => 'cr']);

        $fyStart = now()->setDate($year, 4, 1)->startOfDay();
        $days = max(1, (int) $fyStart->diffInDays(now()->subDay()));
        $date = fn () => $fyStart->copy()->addDays(mt_rand(0, $days))->toDateString();
        $rates = [5, 12, 18, 28];

        // Loans with a few EMIs, so loans and interest get exercised too.
        foreach ([['SBI Term Loan', 1, 2500000, 9.5, 60], ['HDFC Business Loan', 0, 1000000, 14, 36]] as [$lender, $secured, $amount, $rate, $months]) {
            $this->call_(LoanController::class, 'store', $company, [
                'lender' => $lender, 'secured' => $secured, 'principal' => $amount, 'annual_rate' => $rate,
                'tenure_months' => $months, 'start_date' => $fyStart->toDateString(), 'received_in' => $bank->id,
            ]);
            $loan = $company->loans()->latest('id')->first();
            for ($m = 1; $m <= 3; $m++) {
                $loan->refresh();
                $request = Request::create('/', 'POST', [
                    'date' => $fyStart->copy()->addMonths($m)->toDateString(), 'paid_from' => $bank->id,
                    'amount' => (float) $loan->emi, 'interest' => $loan->interestOn($loan->outstanding()),
                ]);
                $request->setUserResolver(fn () => Auth::user());
                app()->instance('request', $request);
                app(LoanController::class)->pay($request, $company, $loan);
            }
        }

        for ($n = 1; $n <= $this->entries; $n++) {
            $item = $items[$n % $items->count()];
            match ($n % 10) {
                // 4 in 10: sales invoices (stock + service lines, line and bill discounts, intra/inter-state).
                0, 1, 2, 3 => $this->post($company, 'gst-invoices.store', [
                    'type' => 'sales', 'date' => $date(), 'party_account_id' => $customers->random()->id,
                    'bill_discount_percent' => $n % 7 === 0 ? 2.5 : null,
                    'lines' => [
                        ['account_id' => $sales->id, 'stock_item_id' => $item->id, 'quantity' => mt_rand(1, 5), 'item_rate' => $item->selling_price,
                            'discount_percent' => $n % 3 === 0 ? 5 : null, 'hsn_sac' => '8471', 'rate' => (string) (int) $item->gst_rate],
                        ['account_id' => $serviceIncome->id, 'taxable' => mt_rand(500, 5000), 'hsn_sac' => '998314', 'rate' => 18, 'description' => 'Installation'],
                    ],
                ]),
                // 2 in 10: purchase invoices.
                4, 5 => $this->post($company, 'gst-invoices.store', [
                    'type' => 'purchase', 'date' => $date(), 'party_account_id' => $suppliers->random()->id, 'reference' => 'PB/'.$n,
                    'lines' => [['account_id' => $purchases->id, 'stock_item_id' => $item->id, 'quantity' => mt_rand(5, 50),
                        'item_rate' => $item->opening_rate, 'hsn_sac' => '8471', 'rate' => (string) (int) $item->gst_rate]],
                ]),
                // Receipt from a customer; some by cheque.
                6 => $this->receipt($company, $bank, $customers->random(), mt_rand(1000, 50000), $date(), $n),
                // Supplier payment from the bank.
                7 => $this->post($company, 'vouchers.store', ['type' => 'payment', 'date' => $date(), 'narration' => 'Supplier payment', 'entries' => [
                    ['account_id' => $suppliers->random()->id, 'debit' => $amt = mt_rand(1000, 40000)], ['account_id' => $sbi->id, 'credit' => $amt],
                ]]),
                // Small expenses in cash.
                8 => $this->post($company, 'vouchers.store', ['type' => 'payment', 'date' => $date(), 'narration' => 'Expense', 'entries' => [
                    ['account_id' => $expenses->random()->id, 'debit' => $amt = mt_rand(100, 3000)], ['account_id' => $cash->id, 'credit' => $amt],
                ]]),
                // Alternately a cash deposit (contra) or an expense provision (journal).
                default => $n % 20 === 9
                    ? $this->post($company, 'vouchers.store', ['type' => 'contra', 'date' => $date(), 'narration' => 'Cash deposit', 'entries' => [
                        ['account_id' => $bank->id, 'debit' => $amt = mt_rand(1000, 5000)], ['account_id' => $cash->id, 'credit' => $amt],
                    ]])
                    : $this->post($company, 'vouchers.store', ['type' => 'journal', 'date' => $date(), 'narration' => 'Expense provision', 'entries' => [
                        ['account_id' => $expenses->random()->id, 'debit' => $amt = mt_rand(500, 5000)], ['account_id' => $suppliers->random()->id, 'credit' => $amt],
                    ]]),
            };

            if ($n % 500 === 0) {
                $this->command?->info("  {$n} / {$this->entries} entries");
            }
        }

        $this->everyModule($company, $customers, $suppliers, $items, $sales, $purchases, $bank, $date);

        $this->command?->info("Bulk Test Traders ready with {$this->entries} entries. Log in as demo@example.com / password.");
    }

    /**
     * One or more records in every remaining module, so every screen has data:
     * price list, estimate / orders / challan, BOM + production, batches, serials,
     * godown + stock journal, credit terms, reorder levels, fixed assets + depreciation.
     */
    protected function everyModule(Company $company, $customers, $suppliers, $items, $sales, $purchases, $bank, $date): void
    {
        $unit = $company->units()->where('symbol', 'Nos')->value('id');

        // Price list with dealer rates, given to the first ten customers.
        $this->act(\App\Http\Controllers\PriceListController::class, 'store', $company, ['name' => 'Dealer']);
        $dealer = $company->priceLists()->firstOrFail();
        $this->act(\App\Http\Controllers\PriceListController::class, 'update', $company, [
            'name' => 'Dealer', 'prices' => $items->mapWithKeys(fn ($i) => [$i->id => round((float) $i->selling_price * 0.9, 2)])->all(),
        ], $dealer);
        $customers->take(10)->each(fn ($p) => $p->update(['price_list_id' => $dealer->id, 'credit_days' => 30, 'credit_limit' => 200000]));

        // Reorder levels: a few items set high so the low-stock alert has something to show.
        $items->each(fn ($i, $k) => $i->update(['reorder_level' => $k < 3 ? 1000000 : 50]));

        // Estimate, sale order, purchase order and delivery challan.
        foreach (['estimate', 'sale_order', 'delivery_challan'] as $type) {
            $this->act(\App\Http\Controllers\BusinessDocumentController::class, 'store', $company, [
                'date' => $date(), 'party_account_id' => $customers->random()->id,
                'lines' => [['account_id' => $sales->id, 'stock_item_id' => $items[1]->id, 'quantity' => 2, 'item_rate' => $items[1]->selling_price, 'rate' => '18', 'discount_percent' => 5]],
            ], $type);
        }
        $this->act(\App\Http\Controllers\BusinessDocumentController::class, 'store', $company, [
            'date' => $date(), 'party_account_id' => $suppliers->random()->id,
            'lines' => [['account_id' => $purchases->id, 'stock_item_id' => $items[2]->id, 'quantity' => 20, 'item_rate' => $items[2]->opening_rate, 'rate' => '18']],
        ], 'purchase_order');

        // Manufacturing: a kit made from two items.
        $kit = $company->stockItems()->create(['name' => 'Combo Kit', 'unit_id' => $unit, 'gst_rate' => 18, 'selling_price' => 2500]);
        $this->act(\App\Http\Controllers\ManufacturingController::class, 'storeBom', $company, [
            'name' => 'Combo Kit recipe', 'stock_item_id' => $kit->id, 'output_quantity' => 1,
            'components' => [['stock_item_id' => $items[0]->id, 'quantity' => 1], ['stock_item_id' => $items[1]->id, 'quantity' => 2]],
        ]);
        $this->act(\App\Http\Controllers\ManufacturingController::class, 'storeProduction', $company, [
            'bom_id' => $company->boms()->value('id'), 'date' => $date(), 'quantity' => 25, 'other_cost' => 500,
        ]);

        // Batch-tracked medicine and serial-tracked phones, bought then sold.
        $medicine = $company->stockItems()->create(['name' => 'Paracetamol Strip', 'unit_id' => $unit, 'gst_rate' => 12, 'selling_price' => 30, 'track_batches' => true]);
        $phone = $company->stockItems()->create(['name' => 'Smartphone', 'unit_id' => $unit, 'gst_rate' => 18, 'selling_price' => 15000, 'track_serials' => true]);
        $buyDate = now()->setDate((int) substr($company->financial_year, 0, 4), 4, 2)->toDateString();
        $this->post($company, 'gst-invoices.store', [
            'type' => 'purchase', 'date' => $buyDate, 'party_account_id' => $suppliers->first()->id, 'reference' => 'PB/BATCH',
            'lines' => [
                ['account_id' => $purchases->id, 'stock_item_id' => $medicine->id, 'quantity' => 500, 'item_rate' => 20, 'rate' => '12', 'batch_no' => 'PCM-01', 'expiry_date' => now()->addMonths(2)->toDateString()],
                ['account_id' => $purchases->id, 'stock_item_id' => $phone->id, 'quantity' => 3, 'item_rate' => 11000, 'rate' => '18', 'serial_numbers' => 'IMEI1001, IMEI1002, IMEI1003'],
            ],
        ]);
        $this->post($company, 'gst-invoices.store', [
            'type' => 'sales', 'date' => $date(), 'party_account_id' => $customers->first()->id,
            'lines' => [
                ['account_id' => $sales->id, 'stock_item_id' => $medicine->id, 'quantity' => 40, 'item_rate' => 30, 'rate' => '12', 'batch_no' => 'PCM-01'],
                ['account_id' => $sales->id, 'stock_item_id' => $phone->id, 'quantity' => 1, 'item_rate' => 15000, 'rate' => '18', 'serial_numbers' => 'IMEI1001'],
            ],
        ]);

        // Second godown and a stock journal into it.
        $this->act(\App\Http\Controllers\GodownController::class, 'store', $company, ['name' => 'Warehouse 2']);
        $this->act(\App\Http\Controllers\GodownController::class, 'transfer', $company, [
            'date' => $buyDate, 'stock_item_id' => $items[5]->id, 'from_godown_id' => '', 'to_godown_id' => $company->godowns()->value('id'), 'quantity' => 500,
        ]);

        // Fixed assets with depreciation rates (depreciation itself is posted from the screen).
        $fixed = $company->accountGroups()->where('code', 'FIXED_ASSETS')->firstOrFail();
        foreach ([['Machinery', 15, 800000], ['Computers', 40, 250000], ['Furniture', 10, 150000]] as [$name, $rate, $cost]) {
            $asset = $company->accounts()->create(['name' => $name, 'account_group_id' => $fixed->id, 'account_type' => 'assets', 'depreciation_rate' => $rate]);
            $this->post($company, 'vouchers.store', ['type' => 'payment', 'date' => $buyDate, 'narration' => $name.' bought', 'entries' => [
                ['account_id' => $asset->id, 'debit' => $cost], ['account_id' => $bank->id, 'credit' => $cost],
            ]]);
        }
    }

    /**
     * Call a controller action with form data; $extra are further route arguments.
     */
    protected function act(string $controller, string $method, Company $company, array $data, ...$extra): void
    {
        $request = Request::create('/', 'POST', $data);
        $request->setUserResolver(fn () => Auth::user());
        app()->instance('request', $request);

        app($controller)->{$method}($request, $company, ...$extra);
    }

    /**
     * Customer receipt into the bank; half are cheques, and one in ten receipts bounces.
     */
    protected function receipt(Company $company, $bank, $customer, int $amount, string $date, int $n): void
    {
        $this->post($company, 'vouchers.store', ['type' => 'receipt', 'date' => $date, 'narration' => 'Payment received', 'entries' => [
            ['account_id' => $bank->id, 'debit' => $amount], ['account_id' => $customer->id, 'credit' => $amount],
        ]]);

        if ($n % 20 === 6) {
            $entry = $company->vouchers()->latest('id')->first()->entries()->where('account_id', $bank->id)->first();
            $entry->update(['instrument_no' => sprintf('%06d', $n)]);
            if ($n % 100 === 26) {
                $request = Request::create('/', 'POST', ['date' => $date]);
                $request->setUserResolver(fn () => Auth::user());
                app()->instance('request', $request);
                app(\App\Http\Controllers\ChequeController::class)->bounce($request, $company, $entry);
            }
        }
    }
}
