<?php

namespace App\Services;

use Throwable;

class CollectFailureAlert
{
    public function __construct(
        private readonly TelegramAlert $telegramAlert,
        private readonly TelegramAlertText $telegramText,
        private readonly BitrixOAuth $oauth,
    ) {}

    /**
     * Send a daily Telegram alert when an API collect fails.
     */
    public function notify(
        string $dedupeKey,
        string $emoji,
        string $title,
        ?string $portalOrDomain,
        string $hint,
        Throwable $e,
    ): void {
        try {
            $domain = $this->resolveDomain($portalOrDomain);
            $error = mb_substr($e->getMessage(), 0, 300);
            $message = $this->telegramText->message(
                $emoji,
                $title,
                $domain,
                $hint."\n".$error,
            );
            $this->telegramAlert->notifyOnce($dedupeKey, $message);
        } catch (Throwable) {
        }
    }

    /**
     * Resolve portal code (AE/HK/TR) or raw domain to a portal domain.
     */
    private function resolveDomain(?string $portalOrDomain): ?string
    {
        if ($portalOrDomain === null) {
            return null;
        }

        $value = trim($portalOrDomain);
        if ($value === '') {
            return null;
        }

        $portals = $this->oauth->configuredPortals();
        $code = strtoupper($value);
        if (isset($portals[$code])) {
            return $portals[$code];
        }

        return $value;
    }
}
