<?php

namespace App\Jobs;

use App\Services\RegruAlert;
use App\Services\RegruApi;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class CollectRegruDomains
{
    use Dispatchable;

    /**
     * Collect REG.RU domains and notify Bitrix when expiration is near.
     *
     * @return list<array{fqdn: string, expire: string|null}>|null
     */
    public function handle(
        RegruApi $regruApi,
        RegruAlert $alertService
    ): ?array {
        $username = trim((string) config('services.regru.username'));
        $password = (string) config('services.regru.password');

        if ($username === '' || $password === '') {
            Log::warning('REG.RU collect skipped: API credentials are not configured.');

            return null;
        }

        try {
            $domains = $regruApi->getDomains();

            foreach ($domains as $domain) {
                $alertService->notifyIfDue($domain);
            }

            return $domains;
        } catch (\Throwable $e) {
            Log::error('REG.RU collect failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
