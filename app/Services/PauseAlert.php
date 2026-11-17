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
        $detail = 'Bitrix шлёт wait, сервер отвечает 403 — BP зависают на паузе.';
        if ($workflowId !== null && $workflowId !== '') {
            $detail .= ' wf='.$workflowId;
        }

        $this->alerts->notifyOnce(
            'pause_wait_rejected:'.($domain !== '' ? $domain : 'unknown'),
            $this->text->message(
                '⏸️',
                'Паузы: wait отклонён',
                $domain !== '' ? $domain : null,
                $detail,
            ),
            $this->alerts->instantTtl(),
        );
    }

    /**
     * Immediate Telegram alert when PauseJob exhausted retries.
     */
    public function jobFailed(string $domain, string $error): void
    {
        $domain = $this->oauth->normalizeDomain($domain);

        $this->alerts->notifyOnce(
            'pause_job_failed:'.($domain !== '' ? $domain : 'unknown'),
            $this->text->message(
                '❌',
                'Паузы: job упал',
                $domain !== '' ? $domain : null,
                $error !== '' ? $error : 'PauseJob failed after retries.',
            ),
            $this->alerts->instantTtl(),
        );
    }
}
