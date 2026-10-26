<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Company backups on their schedule (Company Settings → Data → Backups).
Schedule::command('erp:backup --scheduled')->dailyAt('01:30')->withoutOverlapping();
