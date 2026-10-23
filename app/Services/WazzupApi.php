<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class WazzupApi
{
    /**
     * @return list<array{channel_id: string, transport: string, state: string, plain_id: string, name: string}>
     */
    public function getChannels(): array
    {
        $apiKey = trim((string) config('services.wazzup.api_key'));
        $baseUrl = rtrim((string) config('services.wazzup.api_url', 'https://api.wazzup24.com/v3'), '/');

        if ($apiKey === '') {
            throw new RuntimeException('Wazzup API key is not configured.');
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(60)
            ->get($baseUrl.'/channels')
            ->throw()
            ->json();

        $rows = is_array($response) ? $response : [];
        if (isset($rows['data']) && is_array($rows['data'])) {
            $rows = $rows['data'];
        }

        $channels = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $channelId = trim((string) ($row['channelId'] ?? $row['channel_id'] ?? ''));
            if ($channelId === '') {
                continue;
            }

            $channels[] = [
                'channel_id' => $channelId,
                'transport' => trim((string) ($row['transport'] ?? '')),
                'state' => strtolower(trim((string) ($row['state'] ?? ''))),
                'plain_id' => trim((string) ($row['plainId'] ?? $row['plain_id'] ?? '')),
                'name' => trim((string) ($row['name'] ?? '')),
            ];
        }

        return $channels;
    }
}
