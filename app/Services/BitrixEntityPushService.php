<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class BitrixEntityPushService
{
    public function __construct(private readonly BitrixRestClient $bitrixRestClient)
    {
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, string>  $fieldMap
     */
    public function pushMappedChanges(int $bitrixId, array $changes, array $fieldMap, string $method): void
    {
        if ($bitrixId <= 0) {
            return;
        }

        $fields = $this->buildMappedFields($changes, $fieldMap);
        if ($fields === []) {
            return;
        }

        $payload = [
            'id' => $bitrixId,
            'fields' => $fields,
        ];

        $response = $this->bitrixRestClient->post($method, $payload);

        if (! $response->successful()) {
            Log::channel('bitrix_contacts')->error('Bitrix entity push failed', [
                'bitrix_id' => $bitrixId,
                'method' => $method,
                'payload' => $payload,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return;
        }

        Log::channel('bitrix_contacts')->info('Bitrix entity pushed', [
            'bitrix_id' => $bitrixId,
            'method' => $method,
            'fields' => array_keys($fields),
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, string>  $fieldMap
     * @return array<string, mixed>
     */
    private function buildMappedFields(array $changes, array $fieldMap): array
    {
        $fields = [];

        foreach ($fieldMap as $localField => $bitrixField) {
            if (! array_key_exists($localField, $changes)) {
                continue;
            }

            $fields[$bitrixField] = $changes[$localField];
        }

        return $fields;
    }

}
