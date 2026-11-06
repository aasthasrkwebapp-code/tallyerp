<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends messages through the WhatsApp Business (Cloud) API.
 *
 * Until real keys are set in .env, the dummy token puts it in "log" mode:
 * the message is written to the Laravel log instead of being sent.
 */
class WhatsAppCloud
{
    public static function config(): array
    {
        return config('services.whatsapp');
    }

    /**
     * Log mode when switched on, or while the keys are missing or still the dummy ones.
     */
    public static function isLogMode(): bool
    {
        $c = self::config();

        return ($c['driver'] ?? 'log') === 'log'
            || empty($c['token']) || str_starts_with((string) $c['token'], 'dummy')
            || empty($c['phone_number_id']);
    }

    /**
     * @return array{ok: bool, logged: bool, id: ?string, error: ?string}
     */
    public static function send(string $to, string $text): array
    {
        $c = self::config();

        if (self::isLogMode()) {
            Log::info('WhatsApp (log mode, not sent)', ['to' => $to, 'text' => $text]);

            return ['ok' => true, 'logged' => true, 'id' => null, 'error' => null];
        }

        // Business-initiated messages need an approved template; free text works only
        // within 24 hours of the customer's last message.
        $payload = ['messaging_product' => 'whatsapp', 'to' => $to];
        if (! empty($c['template'])) {
            $payload += ['type' => 'template', 'template' => [
                'name' => $c['template'],
                'language' => ['code' => $c['template_language'] ?: 'en'],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $text]]]],
            ]];
        } else {
            $payload += ['type' => 'text', 'text' => ['preview_url' => true, 'body' => $text]];
        }

        $response = Http::withToken($c['token'])->acceptJson()->timeout(15)
            ->post(rtrim($c['base_url'], '/').'/'.$c['api_version'].'/'.$c['phone_number_id'].'/messages', $payload);

        if ($response->failed()) {
            return ['ok' => false, 'logged' => false, 'id' => null, 'error' => $response->json('error.message') ?? 'HTTP '.$response->status()];
        }

        return ['ok' => true, 'logged' => false, 'id' => $response->json('messages.0.id'), 'error' => null];
    }
}
