<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CompanyController;

Route::middleware(['auth'])->group(function () {
    Route::get('/companies', [CompanyController::class, 'index'])
        ->name('companies.index');

    Route::get('/companies/create', [CompanyController::class, 'create'])
        ->name('companies.create');

    Route::post('/companies', [CompanyController::class, 'store'])
        ->name('companies.store');

    Route::get('/companies/{company}', [CompanyController::class, 'show'])
        ->name('companies.show');

    Route::get('/companies/{company}/edit', [CompanyController::class, 'edit'])
        ->name('companies.edit');

    Route::put('/companies/{company}', [CompanyController::class, 'update'])
        ->name('companies.update');

    Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])
        ->name('companies.destroy');
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
