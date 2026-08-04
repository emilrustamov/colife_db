<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\EntitySync;
use App\Services\BitrixRest;
use App\Support\BitrixSoftDelete;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncContactsCommand extends Command
{
    protected $signature = 'bitrix:sync-contacts';

    protected $description = 'Sync contacts from Bitrix24 to local database';

    public function handle(EntitySync $syncService, BitrixRest $bitrixRestClient): int
    {
        $this->info('Bitrix contacts sync started...');

        try {
            $result = $this->syncContacts($syncService, $bitrixRestClient);

            Log::channel('bitrix_contacts')->info('Bitrix contacts sync completed', $result);

            $this->info(sprintf(
                'Completed. Total: %d, created: %d, updated: %d, successful: %d, skipped: %d, failed: %d, marked deleted: %d.',
                $result['total'],
                $result['created'],
                $result['updated'],
                $result['successful'],
                $result['skipped'],
                $result['failed'],
                $result['marked_deleted']
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
     * @return array{total:int, successful:int, skipped:int, failed:int, failed_contact_ids:list<int|string>, marked_deleted:int, created:int, updated:int}
     */
    private function syncContacts(EntitySync $syncService, BitrixRest $bitrixRestClient): array
    {
        $start = 0;
        $total = 0;
        $created = 0;
        $updated = 0;
        $successful = 0;
        $skipped = 0;
        $failedContactIds = [];
        $seenBitrixIds = [];

        while (true) {
            $data = $bitrixRestClient->postJson('crm.contact.list.json', [
                'select' => [
                    'ID',
                    'NAME',
                    'LAST_NAME',
                    'MODIFY_BY_ID',
                    'UF_CRM_1688664973718',
                    'UF_CRM_1696438640',
                    'UF_CRM_1755104713',
                    'UF_CRM_1729690794035',
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

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $bitrixId = (int) ($item['ID'] ?? $item['id'] ?? 0);
                if ($bitrixId > 0) {
                    $seenBitrixIds[] = $bitrixId;
                }
            }

            $batch = $syncService->syncBatch($items);
            $total += $batch['processed'];
            $created += $batch['created'];
            $updated += $batch['updated'];
            $successful += $batch['successful'];
            $skipped += $batch['skipped'];
            $failedContactIds = array_merge($failedContactIds, $batch['failed_ids']);

            $this->info(sprintf(
                'Batch synced. Total processed: %d, created: %d, updated: %d, successful: %d, skipped: %d, failed: %d.',
                $total,
                $created,
                $updated,
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

        $markedDeleted = 0;
        if ($total > 0) {
            $markedDeleted = BitrixSoftDelete::markMissing(
                Contact::class,
                array_values(array_unique($seenBitrixIds)),
                now()
            );
        }

        return [
            'total' => $total,
            'created' => $created,
            'updated' => $updated,
            'successful' => $successful,
            'skipped' => $skipped,
            'failed' => count($failedContactIds),
            'failed_contact_ids' => $failedContactIds,
            'marked_deleted' => $markedDeleted,
        ];
    }
}
