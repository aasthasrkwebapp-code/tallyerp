<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Data\BackupService;
use Illuminate\Console\Command;

/**
 * Backs up companies. With --scheduled only companies whose backup schedule
 * is due are backed up, and old scheduled backups beyond the number to keep
 * are removed. The scheduler runs it daily (routes/console.php).
 */
class BackupCompanies extends Command
{
    protected $signature = 'erp:backup {company?* : Company ids (default: all)} {--scheduled : Only companies whose schedule is due}';

    protected $description = 'Back up company books to storage/app/private/backups';

    public function handle(BackupService $backups): int
    {
        $companies = Company::query()->when($this->argument('company'), fn ($q, $ids) => $q->whereIn('id', $ids))->orderBy('id')->get();
        $failed = 0;

        foreach ($companies as $company) {
            $frequency = $company->setting('backup_frequency');
            if ($this->option('scheduled') && ! $this->due($company, $frequency)) {
                continue;
            }
            $log = $backups->create($company, null, $this->option('scheduled') ? 'scheduled' : 'manual');
            if ($log->status !== 'completed') {
                $failed++;
                $this->error("{$company->name}: backup failed: {$log->error}");

                continue;
            }
            $issues = $backups->verify($log, $company);
            $this->line("{$company->name}: {$log->file_name} ({$log->totalRows()} rows)".($issues ? ' — verification failed: '.implode(' ', $issues) : ', verified'));
            if ($issues) {
                $failed++;
            }
            if ($this->option('scheduled')) {
                $backups->prune($company, (int) $company->setting('backup_keep'));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    protected function due(Company $company, string $frequency): bool
    {
        if ($frequency === 'off') {
            return false;
        }
        $last = \App\Models\BackupLog::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('type', 'scheduled')->where('status', '!=', 'failed')->max('created_at');

        return ! $last || now()->diffInHours($last, true) >= ($frequency === 'weekly' ? 24 * 7 - 1 : 23);
    }
}
