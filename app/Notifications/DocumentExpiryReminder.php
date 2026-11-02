<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** E-mail listing vehicle documents and driving licences that have become due or expired. */
class DocumentExpiryReminder extends Notification
{
    public function __construct(public Company $company, public Collection $rows) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expired = $this->rows->where('status', 'expired');
        $due = $this->rows->where('status', 'due');
        $mail = (new MailMessage)
            ->subject("{$this->company->name}: ".implode(' and ', array_filter([
                $expired->count() ? $expired->count().' document(s) expired' : null,
                $due->count() ? $due->count().' due for renewal' : null,
            ])))
            ->greeting('Vehicle documents and licences');
        foreach ([['Expired', $expired], ['Due for renewal', $due]] as [$heading, $rows]) {
            if ($rows->isEmpty()) {
                continue;
            }
            $mail->line("**{$heading}**");
            foreach ($rows as $r) {
                $when = $r->days < 0 ? 'expired on '.$r->expires_on->format('d-m-Y') : 'expires on '.$r->expires_on->format('d-m-Y').($r->days === 0 ? ' (today)' : " (in {$r->days} days)");
                $mail->line("- {$r->owner}: {$r->label}".($r->number ? " {$r->number}" : '')." {$when}");
            }
        }

        return $mail->action('Open vehicle documents', route('vehicle-documents.index'))
            ->line('You get one e-mail when a document comes within the reminder window and one when it expires. Enter the renewed document to clear it.');
    }
}
