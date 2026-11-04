<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountGroupController;
use App\Http\Controllers\BankReconciliationController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\GstInvoiceController;
use App\Http\Controllers\GstReportController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OutstandingController;
use App\Http\Controllers\StockItemController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\VoucherController;

Route::middleware(['auth'])->group(function () {
    Route::resource('companies', CompanyController::class);
    Route::put('companies/{company}/financial-year', [CompanyController::class, 'changeYear'])
        ->name('companies.financial-year');

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
        });

    Route::get('companies/{company}/banking/reconciliation', [BankReconciliationController::class, 'show'])
        ->name('companies.banking.reconciliation');
    Route::put('companies/{company}/banking/reconciliation', [BankReconciliationController::class, 'update'])
        ->name('companies.banking.reconciliation.update');

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
Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
