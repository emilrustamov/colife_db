<?php

namespace App\Console\Commands;

use App\Models\Apartment;
use App\Services\BitrixStages;
use App\Services\BitrixRest;
use App\Services\BitrixListSync;
use App\Services\Profiles\ApartmentProfile;
use App\Support\BitrixSync;
use Illuminate\Console\Command;
use Throwable;

class SyncApartmentsCommand extends Command
{
    protected $signature = 'bitrix:sync-apartments {--sync-stages=1}';

    protected $description = 'Sync apartments and apartment stages from Bitrix24';

    /**
     * @var list<string>
     */
    private const SELECT_FIELDS = [
        'id',
        'title',
        'stageId',
        'contactId',
        'createdTime',
        'updatedTime',
        'ufCrm6_1682232363193',
        'ufCrm6_1707234311507',
        'ufCrm6_1718821717',
        'ufCrm6_1682232863625',
        'ufCrm6_1682233481671',
        'ufCrm6_1682232312628',
        'ufCrm6_1682238902770',
        'ufCrm6_1689258811986',
        'ufCrm6Adresslink',
        'ufCrm6_1697722222483',
        'ufCrm6_1724248916',
        'ufCrm6_1724247642',
        'ufCrm6_1682235809295',
        'ufCrm6_1686728251990',
        'ufCrm6_1715776920',
        'ufCrm6_1715777017',
        'ufCrm6_1715777650',
        'ufCrm6_1715777670',
        'ufCrm6_1720794204',
        'ufCrm6_1721897304',
        'ufCrm6_1736951470242',
        'ufCrm6_1753278068179',
        'ufCrm6_1682238927645',
        'ufCrm6_1683299159437',
        'ufCrm6_1785314487770',
    ];

    /**
     * Sync apartments via shared SPA list runner with full upsert.
     */
    public function handle(
        BitrixListSync $listSync,
        BitrixRest $bitrixRestClient,
        BitrixStages $stages,
        BitrixSync $syncContext
    ): int {
        $this->info('Bitrix apartments sync started...');
        $now = now();

        try {
            if ($this->option('sync-stages') !== '0') {
                $syncedStages = $stages->sync(
                    $bitrixRestClient,
                    ApartmentProfile::ENTITY_TYPE_ID,
                    'apartment',
                    'Apartments',
                    $now
                );
                $this->info(sprintf('Apartment stages synced: %d', $syncedStages));
            }

            $result = $syncContext->forceUpsert(
                fn (): array => $listSync->sync(
                    ApartmentProfile::ENTITY_TYPE_ID,
                    'apartments',
                    self::SELECT_FIELDS,
                    Apartment::class,
                    $now,
                    function (int $total, int $successful, int $failedCount): void {
                        $this->info(sprintf(
                            'Batch synced. Total processed: %d, successful: %d, failed: %d.',
                            $total,
                            $successful,
                            $failedCount
                        ));
                    }
                )
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
                $this->warn('Failed apartment ids: '.implode(', ', $preview).(count($result['failed_ids']) > 30 ? '...' : ''));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Bitrix apartments sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
