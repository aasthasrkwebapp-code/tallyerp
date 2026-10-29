<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Accounting\RecurringVoucherService;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Makes the vouchers of every recurring voucher that is due, company by company.
 */
class RunRecurringVouchers extends Command
{
    protected $signature = 'erp:recurring-vouchers {company?* : Company ids (default: all)} {--date= : Treat this date as today (Y-m-d)}';

    protected $description = 'Make the vouchers of recurring vouchers that are due';

    public function handle(CurrentCompany $current, RecurringVoucherService $recurring): int
    {
        $asOf = $this->option('date') ?: now()->toDateString();
        $failed = 0;
        $companies = Company::query()->when($this->argument('company'), fn ($q, $ids) => $q->whereIn('id', $ids))->orderBy('id')->get();

        foreach ($companies as $company) {
            $current->set($company);
            // Templates whose creator has left the company run as its owner.
            $owner = $company->user ?? $company->members()->wherePivot('is_active', true)->orderBy('users.id')->first();
            if (! $owner) {
                continue;
            }
            $result = $recurring->runDue($owner, $asOf);
            if ($result['created'] || $result['failed']) {
                $this->line("{$company->name}: {$result['created']} voucher(s) made".($result['failed'] ? ", {$result['failed']} failed" : ''));
            }
            $failed += $result['failed'];
        }
        $current->set(null);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
