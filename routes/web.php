<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancialYearController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ProfileController;
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

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index')->middleware('can:audit.view');
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show')->middleware('can:audit.view');
});

require __DIR__.'/auth.php';
