<?php

namespace App\Console\Commands;

use App\Models\ApartmentOwnership;
use App\Services\BitrixStages;
use App\Services\BitrixRest;
use App\Services\BitrixListSync;
use App\Services\Profiles\OwnershipProfile;
use Illuminate\Console\Command;
use Throwable;

class SyncOwnershipsCommand extends Command
{
    protected $signature = 'bitrix:sync-apartment-ownerships {--sync-stages=1}';

    protected $description = 'Sync landlord apartment ownerships (SPA 148) from Bitrix24';

    /**
     * @var list<string>
     */
    private const SELECT_FIELDS = [
        'id',
        'title',
        'stageId',
        'createdTime',
        'updatedTime',
        'parentId144',
        'ufCrm10_1693823301',
        'ufCrm10_1693823247516',
        'ufCrm10_1693823282826',
        'ufCrm10_1708956056',
        'ufCrm10_1708955996',
        'ufCrm10_1714652654',
        'ufCrm10_1714652687',
        'ufCrm10TerminationDate',
        'ufCrm10_1727422412',
    ];

    /**
     * Sync apartment ownerships via shared SPA list runner.
     */
    public function handle(
        BitrixListSync $listSync,
        BitrixRest $bitrixRestClient,
        BitrixStages $stages
    ): int {
        $this->info('Bitrix apartment ownerships sync started...');
        $now = now();

        try {
            if ($this->option('sync-stages') !== '0') {
                $syncedStages = $stages->sync(
                    $bitrixRestClient,
                    OwnershipProfile::ENTITY_TYPE_ID,
                    'apartment_ownership',
                    'Apartment ownerships',
                    $now
                );
                $this->info(sprintf('Apartment ownership stages synced: %d', $syncedStages));
            }

            $result = $listSync->sync(
                OwnershipProfile::ENTITY_TYPE_ID,
                'apartment_ownerships',
                self::SELECT_FIELDS,
                ApartmentOwnership::class,
                $now,
                function (int $total, int $successful, int $failedCount): void {
                    $this->info(sprintf(
                        'Batch synced. Total processed: %d, successful: %d, failed: %d.',
                        $total,
                        $successful,
                        $failedCount
                    ));
                }
            );

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
                $preview = array_slice($result['failed_ids'], 0, 30);
                $this->warn('Failed ownership ids: '.implode(', ', $preview).(count($result['failed_ids']) > 30 ? '...' : ''));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Bitrix apartment ownerships sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
