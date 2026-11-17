<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\BitrixListSync;
use App\Services\CollectFailureAlert;
use App\Services\Profiles\ContactProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncContactsCommand extends Command
{
    protected $signature = 'bitrix:sync-contacts';

    protected $description = 'Sync contacts from Bitrix24 to local database';

    /**
     * Run contacts list sync with soft-delete reconciliation.
     */
    public function handle(
        BitrixListSync $listSync,
        CollectFailureAlert $alerts,
    ): int {
        $this->info('Bitrix contacts sync started...');

        try {
            $result = $listSync->syncList(
                method: 'crm.contact.list.json',
                payload: [
                    'select' => ContactProfile::select(),
                    'order' => ['ID' => 'ASC'],
                ],
                itemsPath: 'result',
                idKey: 'ID',
                entityKey: 'contacts',
                modelClass: Contact::class,
                now: now(),
                onBatch: function (int $total, int $successful, int $failedCount): void {
                    $this->info(sprintf(
                        'Batch synced. Total processed: %d, successful: %d, failed: %d.',
                        $total,
                        $successful,
                        $failedCount
                    ));
                },
                pauseUs: 200_000,
            );

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
                $this->warn('Failed ids: '.implode(', ', $result['failed_ids']));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::channel('bitrix_contacts')->error('Bitrix contacts sync failed', [
                'error' => $e->getMessage(),
            ]);

            $alerts->notify(
                'bitrix_sync_contacts_failed',
                '📇',
                'Sync contacts failed',
                'AE',
                'bitrix:sync-contacts',
                $e,
            );

            $this->error('Bitrix contacts sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
