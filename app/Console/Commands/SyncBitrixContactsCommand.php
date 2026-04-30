<?php

namespace App\Console\Commands;

use App\Services\BitrixEntitySyncService;
use App\Services\BitrixRestClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncBitrixContactsCommand extends Command
{
    protected $signature = 'bitrix:sync-contacts';

    protected $description = 'Sync contacts from Bitrix24 to local database';

    /**
     * @return int
     */
    public function handle(BitrixEntitySyncService $syncService, BitrixRestClient $bitrixRestClient): int
    {
        $this->info('Bitrix contacts sync started...');

        try {
            $result = $this->syncContacts($syncService, $bitrixRestClient);

            Log::channel('bitrix_contacts')->info('Bitrix contacts sync completed', $result);

            $this->info(sprintf(
                'Completed. Total: %d, successful: %d, skipped: %d, failed: %d.',
                $result['total'],
                $result['successful'],
                $result['skipped'],
                $result['failed']
            ));

            if ($result['failed'] > 0) {
                $this->warn('Failed contact ids: '.implode(', ', $result['failed_contact_ids']));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::channel('bitrix_contacts')->error('Bitrix contacts sync failed', [
                'error' => $e->getMessage(),
            ]);

            $this->error('Bitrix contacts sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array{total:int, successful:int, skipped:int, failed:int, failed_contact_ids:list<int|string>}
     */
    private function syncContacts(BitrixEntitySyncService $syncService, BitrixRestClient $bitrixRestClient): array
    {
        $start = 0;
        $total = 0;
        $successful = 0;
        $skipped = 0;
        $failedContactIds = [];

        while (true) {
            $data = $bitrixRestClient->postJson('crm.contact.list.json', [
                'select' => [
                    'ID',
                    'NAME',
                    'LAST_NAME',
                    'TYPE_ID',
                    'BIRTHDATE',
                    'PHONE',
                    'EMAIL',
                    'DATE_CREATE',
                    'DATE_MODIFY',
                ],
                'order' => ['ID' => 'ASC'],
                'start' => $start,
            ]);

            /** @var list<array<string, mixed>> $items */
            $items = data_get($data, 'result', []);

            if ($items === []) {
                break;
            }

            $batch = $syncService->syncBatchItems($items);
            $total += $batch['processed'];
            $successful += $batch['successful'];
            $skipped += $batch['skipped'];
            $failedContactIds = array_merge($failedContactIds, $batch['failed_ids']);

            $this->info(sprintf(
                'Batch synced. Total processed: %d, successful: %d, skipped: %d, failed: %d.',
                $total,
                $successful,
                $skipped,
                count($failedContactIds)
            ));
            if ($this->getOutput()->isVerbose() && $batch['failed_ids'] !== []) {
                $this->line('Batch failed IDs: '.implode(', ', array_map('strval', $batch['failed_ids'])));
            }

            $next = data_get($data, 'next');
            if (! is_numeric($next)) {
                break;
            }

            $start = (int) $next;
        }

        return [
            'total' => $total,
            'successful' => $successful,
            'skipped' => $skipped,
            'failed' => count($failedContactIds),
            'failed_contact_ids' => $failedContactIds,
        ];
    }
}
