<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramBot
{
    /**
     * Whether Telegram alerts are configured and enabled.
     */
    public function enabled(): bool
    {
        if (! filter_var(config('services.telegram.enabled', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return $this->botToken() !== '' && $this->chatId() !== '';
    }

    /**
     * Send an HTML-formatted message to the configured chat.
     */
    public function send(string $text): bool
    {
        $token = $this->botToken();
        $chatId = $this->chatId();

        if ($token === '' || $chatId === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post($this->apiUrl($token, 'sendMessage'), [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

            if (! $response->successful() || data_get($response->json(), 'ok') !== true) {
                Log::warning('TELEGRAM_SEND_FAILED', [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('TELEGRAM_SEND_EXCEPTION', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Fetch recent bot updates (for telegram:setup).
     *
     * @return list<array<string, mixed>>
     */
    public function getUpdates(int $limit = 20): array
    {
        $token = $this->botToken();
        if ($token === '') {
            return [];
        }

        try {
            $response = Http::timeout(15)
                ->get($this->apiUrl($token, 'getUpdates'), [
                    'limit' => $limit,
                    'timeout' => 0,
                ]);

            $result = data_get($response->json(), 'result', []);

            return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
        } catch (Throwable $e) {
            Log::warning('TELEGRAM_GET_UPDATES_FAILED', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Resolve bot token from config.
     */
    public function botToken(): string
    {
        return trim((string) config('services.telegram.bot_token', ''));
    }

    /**
     * Resolve chat id from config.
     */
    public function chatId(): string
    {
        return trim((string) config('services.telegram.chat_id', ''));
    }

    /**
     * Build Telegram Bot API URL.
     */
    private function apiUrl(string $token, string $method): string
    {
        return 'https://api.telegram.org/bot'.$token.'/'.$method;
    }
}
