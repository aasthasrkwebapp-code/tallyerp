<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountGroupController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\VoucherController;

Route::middleware(['auth'])->group(function () {
    Route::resource('companies', CompanyController::class);

    // scoped() makes {account_group} / {account} resolve only within {company}.
    Route::resource('companies.account-groups', AccountGroupController::class)
        ->except('show')
        ->scoped();
    Route::resource('companies.accounts', AccountController::class)
        ->except('show')
        ->scoped();
    Route::resource('companies.vouchers', VoucherController::class)
        ->only(['index', 'create', 'store', 'show', 'destroy'])
        ->scoped();

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
