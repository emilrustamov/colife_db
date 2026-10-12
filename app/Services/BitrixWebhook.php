<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class BitrixWebhook
{
    /**
     * Call Bitrix REST method via inbound webhook.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function call(string $method, array $payload = [], ?string $webhookUrl = null): array
    {
        $webhook = rtrim((string) ($webhookUrl ?? $this->defaultWebhook()), '/').'/';
        if ($webhook === '/') {
            throw new RuntimeException('Bitrix webhook is not configured.');
        }

        /** @var array<string, mixed> $json */
        $json = Http::asForm()
            ->timeout(120)
            ->post($webhook.ltrim($method, '/'), $payload)
            ->throw()
            ->json();

        return $json;
    }

    /**
     * Call Bitrix REST without throwing on Bitrix business errors.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function callRaw(string $method, array $payload = [], ?string $webhookUrl = null, int $timeout = 120): array
    {
        $webhook = rtrim((string) ($webhookUrl ?? $this->defaultWebhook()), '/').'/';
        if ($webhook === '/') {
            throw new RuntimeException('Bitrix webhook is not configured.');
        }

        $response = Http::asForm()
            ->timeout(max(1, $timeout))
            ->post($webhook.ltrim($method, '/'), $payload);

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Resolve webhook URL for disk/list operations.
     */
    public function diskWebhook(): string
    {
        $disk = trim((string) config('services.bitrix.disk_webhook', ''));
        if ($disk !== '') {
            return $disk;
        }

        return $this->defaultWebhook();
    }

    /**
     * Resolve webhook URL for bizproc operations (needs bizproc scope).
     */
    public function bpWebhook(): string
    {
        $bp = trim((string) config('services.bitrix.bp_webhook', ''));
        if ($bp !== '') {
            return $bp;
        }

        return $this->defaultWebhook();
    }

    /**
     * Default inbound webhook from config.
     */
    private function defaultWebhook(): string
    {
        return (string) config('services.bitrix.webhook', '');
    }
}
