<?php

use App\Http\Controllers\Accounting\VoucherController;
use App\Http\Controllers\Accounting\VoucherTypeController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Banking\BankReconciliationController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\PartyController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancialYearController;
use App\Http\Controllers\Gst\GstReportController;
use App\Http\Controllers\Masters\AccountGroupController;
use App\Http\Controllers\Masters\CostCentreController;
use App\Http\Controllers\Masters\LedgerController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Reports\AccountingReportController;
use App\Http\Controllers\Reports\OutstandingReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'));

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Company list and creation work without an active company.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
    Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
    Route::get('/companies/{company}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
    Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
    Route::post('/companies/{company}/switch', [CompanyController::class, 'switch'])->name('companies.switch');
});

// Everything below runs inside the active company.
Route::middleware(['auth', 'verified', 'company'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('can:company.edit')->group(function () {
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::put('/settings/preferences', [SettingsController::class, 'updatePreferences'])->name('settings.preferences');
    });

    Route::get('/financial-years', [FinancialYearController::class, 'index'])->name('financial-years.index')->middleware('can:periods.view');
    Route::post('/financial-years', [FinancialYearController::class, 'store'])->name('financial-years.store')->middleware('can:periods.create');
    Route::post('/financial-years/{financialYear}/current', [FinancialYearController::class, 'makeCurrent'])->name('financial-years.current')->middleware('can:periods.create');
    Route::post('/financial-years/{financialYear}/lock', [FinancialYearController::class, 'toggleLock'])->name('financial-years.lock')->middleware('can:periods.lock');
    Route::post('/accounting-periods/{period}/lock', [FinancialYearController::class, 'togglePeriodLock'])->name('accounting-periods.lock')->middleware('can:periods.lock');

    Route::get('/members', [MemberController::class, 'index'])->name('members.index')->middleware('can:users.view');
    Route::post('/members', [MemberController::class, 'store'])->name('members.store')->middleware('can:users.create');
    Route::put('/members/{user}', [MemberController::class, 'update'])->name('members.update')->middleware('can:users.edit');
    Route::delete('/members/{user}', [MemberController::class, 'destroy'])->name('members.destroy')->middleware('can:users.delete');

    Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create')->middleware('can:users.create');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store')->middleware('can:users.create');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->name('roles.show')->middleware('can:users.view');
    Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit')->middleware('can:users.edit');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update')->middleware('can:users.edit');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy')->middleware('can:users.delete');

    // Accounting masters
    Route::get('/account-groups', [AccountGroupController::class, 'index'])->name('account-groups.index')->middleware('can:masters.view');
    Route::get('/account-groups/create', [AccountGroupController::class, 'create'])->name('account-groups.create')->middleware('can:masters.create');
    Route::post('/account-groups', [AccountGroupController::class, 'store'])->name('account-groups.store')->middleware('can:masters.create');
    Route::get('/account-groups/{accountGroup}/edit', [AccountGroupController::class, 'edit'])->name('account-groups.edit')->middleware('can:masters.edit');
    Route::put('/account-groups/{accountGroup}', [AccountGroupController::class, 'update'])->name('account-groups.update')->middleware('can:masters.edit');
    Route::delete('/account-groups/{accountGroup}', [AccountGroupController::class, 'destroy'])->name('account-groups.destroy')->middleware('can:masters.delete');

    Route::get('/ledgers', [LedgerController::class, 'index'])->name('ledgers.index')->middleware('can:masters.view');
    Route::get('/ledgers/create', [LedgerController::class, 'create'])->name('ledgers.create')->middleware('can:masters.create');
    Route::post('/ledgers', [LedgerController::class, 'store'])->name('ledgers.store')->middleware('can:masters.create');
    Route::get('/ledgers/{ledger}', [LedgerController::class, 'show'])->name('ledgers.show')->middleware('can:masters.view');
    Route::get('/ledgers/{ledger}/edit', [LedgerController::class, 'edit'])->name('ledgers.edit')->middleware('can:masters.edit');
    Route::put('/ledgers/{ledger}', [LedgerController::class, 'update'])->name('ledgers.update')->middleware('can:masters.edit');
    Route::delete('/ledgers/{ledger}', [LedgerController::class, 'destroy'])->name('ledgers.destroy')->middleware('can:masters.delete');

    Route::get('/cost-centres', [CostCentreController::class, 'index'])->name('cost-centres.index')->middleware('can:masters.view');
    Route::post('/cost-centres', [CostCentreController::class, 'store'])->name('cost-centres.store')->middleware('can:masters.create');
    Route::put('/cost-centres/{costCentre}', [CostCentreController::class, 'update'])->name('cost-centres.update')->middleware('can:masters.edit');
    Route::delete('/cost-centres/{costCentre}', [CostCentreController::class, 'destroy'])->name('cost-centres.destroy')->middleware('can:masters.delete');
    Route::post('/cost-categories', [CostCentreController::class, 'storeCategory'])->name('cost-categories.store')->middleware('can:masters.create');

    // Voucher types and vouchers
    Route::get('/voucher-types', [VoucherTypeController::class, 'index'])->name('voucher-types.index')->middleware('can:voucher_types.view');
    Route::get('/voucher-types/create', [VoucherTypeController::class, 'create'])->name('voucher-types.create')->middleware('can:voucher_types.create');
    Route::post('/voucher-types', [VoucherTypeController::class, 'store'])->name('voucher-types.store')->middleware('can:voucher_types.create');
    Route::get('/voucher-types/{voucherType}/edit', [VoucherTypeController::class, 'edit'])->name('voucher-types.edit')->middleware('can:voucher_types.edit');
    Route::put('/voucher-types/{voucherType}', [VoucherTypeController::class, 'update'])->name('voucher-types.update')->middleware('can:voucher_types.edit');

    Route::get('/vouchers', [VoucherController::class, 'index'])->name('vouchers.index')->middleware('can:vouchers.view');
    Route::get('/vouchers/create', [VoucherController::class, 'create'])->name('vouchers.create')->middleware('can:vouchers.create');
    Route::post('/vouchers', [VoucherController::class, 'store'])->name('vouchers.store')->middleware('can:vouchers.create');
    Route::get('/vouchers/{voucher}', [VoucherController::class, 'show'])->name('vouchers.show')->middleware('can:vouchers.view');
    Route::get('/vouchers/{voucher}/edit', [VoucherController::class, 'edit'])->name('vouchers.edit');
    Route::put('/vouchers/{voucher}', [VoucherController::class, 'update'])->name('vouchers.update');
    Route::post('/vouchers/{voucher}/post', [VoucherController::class, 'post'])->name('vouchers.post');
    Route::post('/vouchers/{voucher}/cancel', [VoucherController::class, 'cancel'])->name('vouchers.cancel')->middleware('can:vouchers.cancel');
    Route::post('/vouchers/{voucher}/reverse', [VoucherController::class, 'reverse'])->name('vouchers.reverse')->middleware('can:vouchers.cancel');
    Route::delete('/vouchers/{voucher}', [VoucherController::class, 'destroy'])->name('vouchers.destroy')->middleware('can:vouchers.delete');
    Route::get('/ledgers/{ledger}/open-bills', [VoucherController::class, 'openBills'])->name('ledgers.open-bills')->middleware('can:vouchers.view');

    // Customers and suppliers (party ledgers with contact and GST details)
    foreach (['customers' => ['customer', 'sales'], 'suppliers' => ['supplier', 'purchases']] as $prefix => [$kind, $module]) {
        Route::prefix($prefix)->name($prefix.'.')->group(function () use ($kind, $module) {
            Route::get('/', [PartyController::class, 'index'])->name('index')->defaults('kind', $kind)->middleware("can:{$module}.view");
            Route::get('/create', [PartyController::class, 'create'])->name('create')->defaults('kind', $kind)->middleware("can:{$module}.create");
            Route::post('/', [PartyController::class, 'store'])->name('store')->defaults('kind', $kind)->middleware("can:{$module}.create");
            Route::get('/{ledger}', [PartyController::class, 'show'])->name('show')->defaults('kind', $kind)->middleware("can:{$module}.view");
            Route::get('/{ledger}/edit', [PartyController::class, 'edit'])->name('edit')->defaults('kind', $kind)->middleware("can:{$module}.edit");
            Route::put('/{ledger}', [PartyController::class, 'update'])->name('update')->defaults('kind', $kind)->middleware("can:{$module}.edit");
            Route::delete('/{ledger}', [PartyController::class, 'destroy'])->name('destroy')->defaults('kind', $kind)->middleware("can:{$module}.delete");
        });
    }

    // Sales invoices, purchase bills, credit and debit notes (permissions checked per kind)
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
    Route::get('/invoices/{invoice}/print', [InvoiceController::class, 'print'])->name('invoices.print');
    Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::post('/invoices/{invoice}/post', [InvoiceController::class, 'post'])->name('invoices.post');
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');

    // Banking: reconciliation and statement import
    Route::get('/banking', [BankReconciliationController::class, 'index'])->name('bank-reconciliation.index')->middleware('can:banking.view');
    Route::post('/banking/{ledger}/dates', [BankReconciliationController::class, 'update'])->name('bank-reconciliation.update')->middleware('can:banking.edit');
    Route::post('/banking/{ledger}/import', [BankReconciliationController::class, 'import'])->name('bank-reconciliation.import')->middleware('can:banking.create');
    Route::post('/banking/{ledger}/auto-match', [BankReconciliationController::class, 'autoMatch'])->name('bank-reconciliation.auto-match')->middleware('can:banking.edit');
    Route::post('/bank-statement-lines/{line}/match', [BankReconciliationController::class, 'match'])->name('bank-statement-lines.match')->middleware('can:banking.edit');
    Route::post('/bank-statement-lines/{line}/unmatch', [BankReconciliationController::class, 'unmatch'])->name('bank-statement-lines.unmatch')->middleware('can:banking.edit');
    Route::delete('/bank-statement-lines/{line}', [BankReconciliationController::class, 'destroyLine'])->name('bank-statement-lines.destroy')->middleware('can:banking.delete');

    // GST returns (working papers)
    Route::middleware('can:gst.view')->prefix('gst')->name('gst.')->group(function () {
        Route::get('/gstr-1', [GstReportController::class, 'gstr1'])->name('gstr1');
        Route::get('/gstr-3b', [GstReportController::class, 'gstr3b'])->name('gstr3b');
        Route::get('/purchase-register', [GstReportController::class, 'purchaseRegister'])->name('purchase-register');
    });

    // Accounting reports
    Route::middleware('can:reports.view')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/trial-balance', [AccountingReportController::class, 'trialBalance'])->name('trial-balance');
        Route::get('/profit-loss', [AccountingReportController::class, 'profitLoss'])->name('profit-loss');
        Route::get('/balance-sheet', [AccountingReportController::class, 'balanceSheet'])->name('balance-sheet');
        Route::get('/day-book', [AccountingReportController::class, 'dayBook'])->name('day-book');
        Route::get('/ledger', [AccountingReportController::class, 'ledger'])->name('ledger');
        Route::get('/groups/{group}', [AccountingReportController::class, 'groupSummary'])->name('group-summary');
        Route::get('/outstanding', [OutstandingReportController::class, 'index'])->name('outstanding');
        Route::get('/outstanding/{ledger}', [OutstandingReportController::class, 'party'])->name('outstanding.party');
    });

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index')->middleware('can:audit.view');
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show')->middleware('can:audit.view');
});

require __DIR__.'/auth.php';
