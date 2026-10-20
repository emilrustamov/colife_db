<?php

namespace App\Jobs;

use App\Models\AlmaContact;
use App\Services\AlmaApi;
use App\Services\AlmaContactService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class CollectAlmaContacts
{
    use Dispatchable;

    /**
     * Sync Alma clients into local cache, writing only changed rows.
     */
    public function handle(
        AlmaApi $almaApi,
        AlmaContactService $contactService
    ): void {
        $apiUrl = trim((string) config('services.alma.api_url'));
        $apiKey = trim((string) config('services.alma.api_key'));

        if ($apiUrl === '' || $apiKey === '') {
            Log::warning('Alma contacts collect skipped: credentials are not configured.');

            return;
        }

        try {
            $clients = $almaApi->listClients();
            $existing = AlmaContact::query()
                ->get(['id', 'alma_id', 'bitrix_id', 'email'])
                ->keyBy('alma_id');

            $created = 0;
            $updated = 0;
            $unchanged = 0;

            foreach ($clients as $client) {
                $almaId = (int) ($client['id'] ?? 0);
                if ($almaId <= 0) {
                    continue;
                }

                $email = $contactService->normalizeEmail(
                    isset($client['email']) ? (string) $client['email'] : null
                );
                $bitrixId = trim((string) ($client['external_id'] ?? ''));
                $bitrixId = $bitrixId !== '' ? $bitrixId : null;

                $current = $existing->get($almaId);
                if (
                    $current !== null
                    && (string) $current->email === $email
                    && (string) $current->bitrix_id === (string) $bitrixId
                ) {
                    $unchanged++;

                    continue;
                }

                AlmaContact::query()->updateOrCreate(
                    ['alma_id' => $almaId],
                    [
                        'email' => $email !== '' ? $email : null,
                        'bitrix_id' => $bitrixId,
                    ]
                );

                if ($current === null) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            Log::info('Alma contacts collect finished', [
                'total' => count($clients),
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
            ]);
        } catch (\Throwable $e) {
            Log::error('Alma contacts collect failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
