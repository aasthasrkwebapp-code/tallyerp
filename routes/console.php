<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Company backups on their schedule (Company Settings → Data → Backups).
Schedule::command('erp:backup --scheduled')->dailyAt('01:30')->withoutOverlapping();

// Recurring vouchers that are due (Transactions → Recurring Vouchers).
Schedule::command('erp:recurring-vouchers')->dailyAt('00:30')->withoutOverlapping();
Schedule::command('erp:recurring-invoices')->dailyAt('00:40')->withoutOverlapping();

// Uploaded import files that were never imported.
Schedule::call(function () {
    foreach (glob(storage_path('app/private/imports/*')) ?: [] as $file) {
        if (is_file($file) && filemtime($file) < now()->subDay()->getTimestamp()) {
            @unlink($file);
        }
    }
})->name('erp:clean-imports')->daily();

// Vehicle document and driving licence expiry reminders (Transport → Vehicle Documents).
Schedule::command('erp:document-reminders')->dailyAt('07:00')->withoutOverlapping();
