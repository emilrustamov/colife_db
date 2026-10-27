<?php

namespace App\Services;

class PauseAlert
{
    public function __construct(
        private readonly TelegramAlert $alerts,
        private readonly TelegramAlertText $text,
        private readonly BitrixOAuth $oauth,
    ) {}

    /**
     * Immediate Telegram alert when /api/bp/wait is rejected (auth/domain).
     */
    public function waitRejected(?string $domain, ?string $workflowId = null): void
    {
        $domain = $this->oauth->normalizeDomain((string) $domain);
        $key = 'pause_wait_rejected:'.($domain !== '' ? $domain : 'unknown');

        $detail = 'Bitrix шлёт wait, сервер отвечает 403 — BP зависают на паузе.';
        if ($workflowId !== null && $workflowId !== '') {
            $detail .= ' wf='.$workflowId;
        }

        $this->alerts->notifyOnce(
            $key,
            $this->text->message(
                '⏸️',
                'Паузы: wait отклонён',
                $domain !== '' ? $domain : null,
                $detail,
            ),
            $this->instantTtl(),
        );
    }

    /**
     * Immediate Telegram alert when PauseJob exhausted retries.
     */
    public function jobFailed(string $domain, string $error): void
    {
        $domain = $this->oauth->normalizeDomain($domain);
        $key = 'pause_job_failed:'.($domain !== '' ? $domain : 'unknown');

        $this->alerts->notifyOnce(
            $key,
            $this->text->message(
                '❌',
                'Паузы: job упал',
                $domain !== '' ? $domain : null,
                $error !== '' ? $error : 'PauseJob failed after retries.',
            ),
            $this->instantTtl(),
        );
    }

    /**
     * Shorter dedupe so a new breakage after a fix still notifies today.
     */
    private function instantTtl(): int
    {
        $ttl = (int) config('services.telegram.instant_dedupe_ttl', 3600);

        return $ttl > 0 ? $ttl : 3600;
    }
}
