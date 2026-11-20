<?php

namespace App\Services;

class CleaningAlert
{
    public function __construct(
        private readonly TelegramAlert $alerts,
        private readonly TelegramAlertText $text,
        private readonly BitrixOAuth $oauth,
    ) {}

    /**
     * Alert when cleaning form submit fails after auth (Bitrix API / upload / tasks).
     */
    public function submitFailed(?string $domain, string $error, ?int $apartmentId = null): void
    {
        $domain = $this->domainOrTr($domain);
        $detail = $error !== '' ? mb_substr($error, 0, 300) : 'Cleaning submit failed.';
        if ($apartmentId !== null && $apartmentId > 0) {
            $detail = 'apartment='.$apartmentId."\n".$detail;
        }

        $this->alerts->notifyOnce(
            'cleaning_submit_failed:'.$domain,
            $this->text->message('🧹', 'Cleaning submit упал', $domain, $detail),
            $this->alerts->instantTtl(),
        );
    }

    /**
     * Alert when cleaning form auth is rejected (403).
     */
    public function submitRejected(?string $domain): void
    {
        $domain = $this->domainOrTr($domain);

        $this->alerts->notifyOnce(
            'cleaning_submit_rejected:'.$domain,
            $this->text->message(
                '🧹',
                'Cleaning: доступ отклонён',
                $domain,
                'Форма Cleaning отвечает 403 — проверь install/OAuth TR.',
            ),
            $this->alerts->instantTtl(),
        );
    }

    private function domainOrTr(?string $domain): string
    {
        $normalized = $this->oauth->normalizeDomain((string) $domain);
        if ($normalized !== '') {
            return $normalized;
        }

        return $this->oauth->normalizeDomain((string) config('services.b24_tr.portal_domain', ''));
    }
}
