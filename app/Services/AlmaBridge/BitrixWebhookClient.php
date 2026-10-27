<?php

namespace App\Services\AlmaBridge;

use App\Services\BitrixWebhook;
use RuntimeException;

class BitrixWebhookClient
{
    private function __construct(
        private readonly BitrixWebhook $webhook,
        private readonly string $webhookUrl,
    ) {}

    /**
     * Call Bitrix REST method via incoming webhook URL.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        try {
            return $this->webhook->call($method, $params, $this->webhookUrl);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Bitrix24 API error: '.substr($e->getMessage(), 0, 200),
                0,
                $e
            );
        }
    }

    /**
     * Build client for a named Alma Bridge project.
     */
    public static function forProject(string $projectName): self
    {
        $cfg = ProjectMapping::project($projectName);
        $url = trim((string) ($cfg['webhook_url'] ?? ''));
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException("PROJECT webhook_url is empty or invalid for {$projectName}.");
        }

        return new self(app(BitrixWebhook::class), rtrim($url, '/').'/');
    }

    /**
     * REST user id embedded in webhook path /rest/{userId}/.
     */
    public function restUserId(): ?int
    {
        if (preg_match('#/rest/(\d+)/#', $this->webhookUrl, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
