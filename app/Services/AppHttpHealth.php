<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class AppHttpHealth
{
    public function __construct(
        private readonly TelegramAlert $alerts,
        private readonly TelegramAlertText $text,
    ) {}

    /**
     * Probe local APP_URL/up (nginx + php-fpm reachable).
     *
     * @return list<string>
     */
    public function check(): array
    {
        $url = rtrim((string) config('app.url'), '/').'/up';

        try {
            $response = Http::timeout(10)
                ->withOptions(['allow_redirects' => true])
                ->get($url);
            if ($response->status() >= 200 && $response->status() < 300) {
                return [];
            }
        } catch (Throwable) {
        }

        $message = $this->text->message(
            '🌐',
            'API отвалился',
            null,
            'Bitrix не достучится до пауз и вебхуков.',
        );
        $this->alerts->notifyOnce('http_up', $message);

        return [$message];
    }
}
