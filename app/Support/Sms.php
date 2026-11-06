<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends SMS through an HTTP SMS gateway (MSG91, Fast2SMS, Textlocal and similar).
 *
 * Until real keys are set in .env, the dummy key puts it in "log" mode:
 * the message is written to the Laravel log instead of being sent.
 */
class Sms
{
    public static function config(): array
    {
        return config('services.sms');
    }

    public static function isLogMode(): bool
    {
        $c = self::config();

        return ($c['driver'] ?? 'log') === 'log'
            || empty($c['api_key']) || str_starts_with((string) $c['api_key'], 'dummy')
            || empty($c['url']);
    }

    /**
     * @return array{ok: bool, logged: bool, error: ?string}
     */
    public static function send(string $to, string $text): array
    {
        $c = self::config();

        if (self::isLogMode()) {
            Log::info('SMS (log mode, not sent)', ['to' => $to, 'sender' => $c['sender_id'], 'text' => $text]);

            return ['ok' => true, 'logged' => true, 'error' => null];
        }

        $response = Http::withToken($c['api_key'])->acceptJson()->timeout(15)->post($c['url'], [
            'sender' => $c['sender_id'],
            'to' => $to,
            'message' => $text,
            'template_id' => $c['dlt_template_id'] ?: null,
        ]);

        if ($response->failed()) {
            return ['ok' => false, 'logged' => false, 'error' => $response->json('message') ?? 'HTTP '.$response->status()];
        }

        return ['ok' => true, 'logged' => false, 'error' => null];
    }
}
