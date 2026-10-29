<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Notifications\DocumentExpiryReminder;
use App\Services\Transport\DocumentExpiry;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

/**
 * E-mails each company's owner about vehicle documents and driving licences that have come
 * within the reminder window or expired. Each reminder is sent once per document and expiry date.
 */
class SendDocumentReminders extends Command
{
    protected $signature = 'erp:document-reminders {company?* : Company ids (default: all)} {--date= : Treat this date as today (Y-m-d)}';

    protected $description = 'E-mail reminders for vehicle documents and driving licences that are due or expired';

    public function handle(CurrentCompany $current, DocumentExpiry $expiry): int
    {
        $asOf = $this->option('date') ?: now()->toDateString();
        $companies = Company::query()->when($this->argument('company'), fn ($q, $ids) => $q->whereIn('id', $ids))->orderBy('id')->get();

        foreach ($companies as $company) {
            $current->set($company);
            if (! $company->setting('document_reminder_email', true) || ! $company->user) {
                continue;
            }
            $rows = $expiry->unsentReminders($asOf);
            if ($rows->isEmpty()) {
                continue;
            }
            $company->user->notify(new DocumentExpiryReminder($company, $rows));
            $expiry->markSent($rows);
            $this->line("{$company->name}: reminded about {$rows->count()} document(s)");
        }
        $current->set(null);

        return self::SUCCESS;
    }
}
