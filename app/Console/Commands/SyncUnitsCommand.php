<?php

namespace App\Console\Commands;

use App\Models\Unit;
use App\Services\BitrixStages;
use App\Services\BitrixRest;
use App\Services\BitrixListSync;
use App\Services\Profiles\UnitProfile;
use App\Support\BitrixSync;
use Illuminate\Console\Command;
use Throwable;

class SyncUnitsCommand extends Command
{
    protected $signature = 'bitrix:sync-units {--sync-stages=1}';

    protected $description = 'Sync units directory and unit stages from Bitrix24';

    /**
     * @var list<string>
     */
    private const SELECT_FIELDS = [
        'id',
        'title',
        'stageId',
        'createdTime',
        'updatedTime',
        'ufCrm8_1684429208',
        'ufCrm8_1698838056004',
        'ufCrm6_1707234311507',
    ];

    /**
     * Sync units via shared SPA list runner with full upsert.
     */
    public function handle(
        BitrixListSync $listSync,
        BitrixRest $bitrixRestClient,
        BitrixStages $stages,
        BitrixSync $syncContext
    ): int {
        $this->info('Bitrix units directory sync started...');
        $now = now();

        try {
            if ($this->option('sync-stages') !== '0') {
                $syncedStages = $stages->sync(
                    $bitrixRestClient,
                    UnitProfile::ENTITY_TYPE_ID,
                    'unit',
                    'Units',
                    $now
                );
                $this->info(sprintf('Unit stages synced: %d', $syncedStages));
            }

            $result = $syncContext->forceUpsert(
                fn (): array => $listSync->sync(
                    UnitProfile::ENTITY_TYPE_ID,
                    'units',
                    self::SELECT_FIELDS,
                    Unit::class,
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
                $this->warn('Failed unit ids: '.implode(', ', $preview).(count($result['failed_ids']) > 30 ? '...' : ''));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Bitrix units directory sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
