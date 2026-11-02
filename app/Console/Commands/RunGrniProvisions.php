<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Inventory\GrniService;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Posts the month-end GRNI provision for companies that turned it on (Inventory → GRNI
 * Provision). It runs daily, so a month missed while the server was down, or blocked by a
 * locked period, is posted on a later day once possible.
 */
class RunGrniProvisions extends Command
{
    protected $signature = 'erp:grni-provisions {company?* : Company ids (default: all)} {--date= : Treat this date as today (Y-m-d)}';

    protected $description = 'Post the automatic month-end provision for goods received not invoiced';

    public function handle(CurrentCompany $current, GrniService $grni): int
    {
        $today = $this->option('date') ?: now()->toDateString();
        $failed = 0;
        $previous = $current->get();
        $companies = Company::query()->when($this->argument('company'), fn ($q, $ids) => $q->whereIn('id', $ids))->orderBy('id')->get();

        foreach ($companies as $company) {
            if (! $company->setting('grni_auto', false) || ! $company->setting('enable_inventory', true)) {
                continue;
            }
            $current->set($company);
            $owner = $company->user ?? $company->members()->wherePivot('is_active', true)->orderBy('users.id')->first();
            if (! $owner) {
                continue;
            }
            $result = $grni->runMonthEnd($company, $owner, $today);
            if ($result['status'] !== 'skipped') {
                $this->line("{$company->name}: {$result['date']} {$result['status']}. {$result['message']}");
            }
            $failed += $result['status'] === 'failed' ? 1 : 0;
        }
        $current->set($previous);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
