<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The 6-digit code for two-factor sign-in by e-mail, or for turning it on. */
class SignInCode extends Notification
{
    public function __construct(public string $code, public int $minutes, public bool $setup = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(config('app.name').($this->setup ? ' code to turn on e-mail sign-in codes: ' : ' sign-in code: ').$this->code)
            ->greeting('Your code is '.$this->code)
            ->line($this->setup
                ? 'Type it on the two-factor sign-in page to turn on e-mail codes.'
                : 'Type it on the sign-in page to finish signing in.')
            ->line("It works once and expires in {$this->minutes} minutes.")
            ->line('If you did not just try to sign in, someone may know your password. Change it from your profile.');
    }
}
