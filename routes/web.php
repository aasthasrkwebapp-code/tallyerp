<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\AccountGroupController;
use App\Http\Controllers\BankReconciliationController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\BusinessDocumentController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyMemberController;
use App\Http\Controllers\CostCentreController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ForexController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\GstInvoiceController;
use App\Http\Controllers\GstReportController;
use App\Http\Controllers\ChequeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\ManufacturingController;
use App\Http\Controllers\OutstandingController;
use App\Http\Controllers\StockItemController;
use App\Http\Controllers\TdsController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\VoucherController;

Route::middleware(['auth'])->group(function () {
    // Before the companies resource so "restore" isn't read as a company id.
    Route::get('companies/restore', [BackupController::class, 'restoreForm'])->name('companies.restore');
    Route::post('companies/restore', [BackupController::class, 'restore'])->name('companies.restore.store');
    Route::get('companies/{company}/backup', [BackupController::class, 'download'])->name('companies.backup');

    Route::resource('companies', CompanyController::class);
    Route::put('companies/{company}/financial-year', [CompanyController::class, 'changeYear'])
        ->name('companies.financial-year');
    Route::get('companies/{company}/audit', AuditLogController::class)->name('companies.audit');
    Route::resource('companies.members', CompanyMemberController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    // scoped() makes {account_group} / {account} resolve only within {company}.
    Route::resource('companies.account-groups', AccountGroupController::class)
        ->except('show')
        ->scoped();
    Route::resource('companies.accounts', AccountController::class)
        ->except('show')
        ->scoped();
    Route::resource('companies.vouchers', VoucherController::class)
        ->except([])
        ->scoped();

    Route::get('companies/{company}/gst-invoices/{voucher}/e-invoice', [GstInvoiceController::class, 'eInvoice'])
        ->name('companies.gst-invoices.e-invoice');
    Route::post('companies/{company}/gst-invoices/{voucher}/e-way-bill', [GstInvoiceController::class, 'eWayBill'])
        ->name('companies.gst-invoices.e-way-bill');
    Route::put('companies/{company}/gst-invoices/{voucher}/e-way-bill', [GstInvoiceController::class, 'saveEWayBillNumber'])
        ->name('companies.gst-invoices.e-way-bill.save');
    Route::get('companies/{company}/gst-invoices/{voucher}/receipt', [GstInvoiceController::class, 'receipt'])
        ->name('companies.gst-invoices.receipt');
    // Estimates, orders and challans: /companies/{company}/documents/{type}/...
    Route::controller(BusinessDocumentController::class)
        ->prefix('companies/{company}/documents/{type}')
        ->name('companies.documents.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('{document}', 'show')->name('show');
            Route::get('{document}/edit', 'edit')->name('edit');
            Route::put('{document}', 'update')->name('update');
            Route::delete('{document}', 'destroy')->name('destroy');
            Route::post('{document}/convert', 'convert')->name('convert');
        });

    Route::resource('companies.gst-invoices', GstInvoiceController::class)
        ->only(['create', 'store', 'show'])
        ->parameters(['gst-invoices' => 'voucher'])
        ->scoped();

    Route::controller(GstReportController::class)
        ->prefix('companies/{company}/gst')
        ->name('companies.gst.')
        ->group(function () {
            Route::get('gstr-1', 'gstr1')->name('gstr1');
            Route::get('gstr-3b', 'gstr3b')->name('gstr3b');
        });

    Route::get('companies/{company}/cheques', [ChequeController::class, 'index'])
        ->name('companies.cheques.index');
    Route::post('companies/{company}/cheques/{entry}/bounce', [ChequeController::class, 'bounce'])
        ->name('companies.cheques.bounce');

    Route::resource('companies.loans', LoanController::class)
        ->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::post('companies/{company}/loans/{loan}/pay', [LoanController::class, 'pay'])
        ->name('companies.loans.pay');

    Route::controller(ManufacturingController::class)
        ->prefix('companies/{company}/manufacturing')
        ->name('companies.manufacturing.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('bom/create', 'createBom')->name('bom.create');
            Route::post('bom', 'storeBom')->name('bom.store');
            Route::get('bom/{bom}/edit', 'editBom')->name('bom.edit');
            Route::put('bom/{bom}', 'updateBom')->name('bom.update');
            Route::delete('bom/{bom}', 'destroyBom')->name('bom.destroy');
            Route::get('production/create', 'createProduction')->name('production.create');
            Route::post('production', 'storeProduction')->name('production.store');
            Route::delete('production/{production}', 'destroyProduction')->name('production.destroy');
        });

    Route::resource('companies.price-lists', PriceListController::class)
        ->only(['index', 'store', 'edit', 'update', 'destroy']);

    Route::get('companies/{company}/stock-items/labels', [StockItemController::class, 'labels'])
        ->name('companies.stock-items.labels');
    Route::resource('companies.stock-items', StockItemController::class)
        ->except('show')
        ->scoped();

    Route::controller(InventoryController::class)
        ->prefix('companies/{company}/inventory')
        ->name('companies.inventory.')
        ->group(function () {
            Route::get('setup', 'setup')->name('setup');
            Route::post('units', 'storeUnit')->name('units.store');
            Route::delete('units/{unit}', 'destroyUnit')->name('units.destroy');
            Route::post('groups', 'storeGroup')->name('groups.store');
            Route::delete('groups/{stockGroup}', 'destroyGroup')->name('groups.destroy');
            Route::get('summary', 'summary')->name('summary');
            Route::get('batches', 'batches')->name('batches');
            Route::get('serials', 'serials')->name('serials');
        });

    Route::get('companies/{company}/banking/reconciliation', [BankReconciliationController::class, 'show'])
        ->name('companies.banking.reconciliation');
    Route::put('companies/{company}/banking/reconciliation', [BankReconciliationController::class, 'update'])
        ->name('companies.banking.reconciliation.update');
    Route::post('companies/{company}/banking/reconciliation/import', [BankReconciliationController::class, 'import'])
        ->name('companies.banking.reconciliation.import');

    Route::get('companies/{company}/budgets', [BudgetController::class, 'index'])->name('companies.budgets.index');
    Route::get('companies/{company}/budgets/edit', [BudgetController::class, 'edit'])->name('companies.budgets.edit');
    Route::put('companies/{company}/budgets', [BudgetController::class, 'update'])->name('companies.budgets.update');

    Route::get('companies/{company}/currencies', [ForexController::class, 'index'])->name('companies.currencies.index');
    Route::post('companies/{company}/currencies', [ForexController::class, 'store'])->name('companies.currencies.store');
    Route::delete('companies/{company}/currencies/{currency}', [ForexController::class, 'destroy'])->name('companies.currencies.destroy');
    Route::get('companies/{company}/forex-revaluation', [ForexController::class, 'revaluation'])->name('companies.forex.revaluation');
    Route::post('companies/{company}/forex-revaluation', [ForexController::class, 'postRevaluation'])->name('companies.forex.post');

    Route::resource('companies.cost-centres', CostCentreController::class)
        ->only(['index', 'store', 'destroy'])
        ->scoped();

    Route::resource('companies.tds', TdsController::class)
        ->only(['index', 'create', 'store']);

    Route::resource('companies.employees', EmployeeController::class)
        ->except('show')
        ->scoped();
    Route::resource('companies.payroll', PayrollController::class)
        ->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::get('companies/{company}/payroll/{payroll}/payslips/{payslip}', [PayrollController::class, 'payslip'])
        ->name('companies.payroll.payslip');

    Route::get('companies/{company}/outstanding/{kind}', [OutstandingController::class, 'show'])
        ->whereIn('kind', ['receivables', 'payables'])
        ->name('companies.outstanding');

    Route::controller(ReportController::class)
        ->prefix('companies/{company}/reports')
        ->name('companies.reports.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('trial-balance', 'trialBalance')->name('trial-balance');
            Route::get('profit-and-loss', 'profitAndLoss')->name('profit-and-loss');
            Route::get('balance-sheet', 'balanceSheet')->name('balance-sheet');
            Route::get('ledger', 'ledger')->name('ledger');
        });
});
// Invoice link shared with customers (WhatsApp etc.): signed, no login.
Route::get('i/{voucher}', [GstInvoiceController::class, 'publicShow'])
    ->middleware('signed')
    ->name('invoices.public');

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    $user = auth()->user();

    $companies = \App\Models\Company::accessibleBy($user)->orderBy('name')->get()
        ->map(fn ($company) => [
            $company,
            $company->roleFor($user),
            $company->userCan($user, 'reports') ? \App\Support\Dashboard::figures($company) : null,
        ]);

    return view('dashboard', compact('companies'));
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
