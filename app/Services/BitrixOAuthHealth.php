<?php

namespace App\Services;

use App\Models\BitrixToken;
use Illuminate\Support\Carbon;

class BitrixOAuthHealth
{
    public function __construct(
        private readonly BitrixAuth $auth,
        private readonly TelegramAlert $alerts,
        private readonly TelegramAlertText $text,
        private readonly BitrixOAuth $oauth,
    ) {}

    /**
     * Check stored Bitrix OAuth tokens and alert when refresh is broken.
     *
     * @return list<string>
     */
    public function check(): array
    {
        $messages = [];

        foreach (BitrixToken::query()->orderBy('id')->get() as $token) {
            $domain = $this->oauth->normalizeDomain((string) $token->domain);
            if ($domain === '') {
                continue;
            }

            if ($this->checkToken($token, $domain) === null) {
                continue;
            }

            $messages[] = $this->alertDead($domain);
        }

        return array_values(array_filter($messages));
    }

    /**
     * Immediate Telegram alert when OAuth refresh fails during live API use.
     */
    public function alertDead(string $domain): string
    {
        $domain = $this->oauth->normalizeDomain($domain);
        $appUrl = rtrim((string) config('app.url'), '/');
        $install = $this->oauth->portalCode($domain) === 'TR'
            ? "{$appUrl}/api/cleaning/install"
            : "{$appUrl}/api/bp/install";

        $message = $this->text->message(
            '🔐',
            'OAuth отвалился',
            $domain !== '' ? $domain : null,
            "Переустанови приложение: {$install}",
        );

        if ($domain !== '') {
            $this->alerts->notifyOnce('oauth:'.$domain, $message);
        }

        return $message;
    }

    /**
     * Validate one portal token; return human reason when broken.
     */
    private function checkToken(BitrixToken $token, string $domain): ?string
    {
        $refreshToken = trim((string) ($token->refresh_token ?: $token->refresh_id));
        if ($refreshToken === '') {
            return 'нет refresh_token';
        }

        $expiresAt = $token->expires_at;
        $stillFresh = $expiresAt instanceof Carbon && $expiresAt->gt(now()->addMinutes(5));
        if ($stillFresh && trim((string) $token->access_token) !== '') {
            return null;
        }

        $response = $this->auth->refresh($refreshToken, $domain);
        if (trim((string) ($response['access_token'] ?? '')) !== '') {
            $this->auth->saveTokens($token, $response, $refreshToken);

            return null;
        }

        $error = trim((string) ($response['error'] ?? 'unknown'));

        return $error !== '' ? $error : 'refresh failed';
    }
}
