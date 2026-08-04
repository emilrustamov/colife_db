<?php

namespace App\Console\Commands;

use App\Models\UnitStay;
use App\Services\BitrixStages;
use App\Services\BitrixRest;
use App\Services\BitrixListSync;
use App\Services\Profiles\UnitStayProfile;
use Illuminate\Console\Command;
use Throwable;

class SyncUnitStaysCommand extends Command
{
    protected $signature = 'bitrix:sync-unit-stays {--sync-stages=1}';

    protected $description = 'Sync tenant unit stays (SPA 183) from Bitrix24';

    /**
     * @var list<string>
     */
    private const SELECT_FIELDS = [
        'id',
        'title',
        'stageId',
        'createdTime',
        'updatedTime',
        'contactId',
        'opportunity',
        'currencyId',
        'parentId2',
        'parentId167',
        'ufCrm20_1693919019',
        'ufCrm20_1700215654',
        'ufCrm20Deal',
        'ufCrm20_1693561495',
        'ufCrm20TypeOfDeal',
        'ufCrm20TypeOfPayment',
        'ufCrm20_1744800159',
        'ufCrm20_1744800193',
        'ufCrm20HowManyMonths',
        'ufCrm20RentalPrice',
        'ufCrm20Deposit',
        'ufCrm20TotalContractAmount',
        'ufCrm20_1696523391',
        'ufCrm20ContractStartDate',
        'ufCrm20ContractEndDate',
    ];

    /**
     * Sync unit stays via shared SPA list runner.
     */
    public function handle(
        BitrixListSync $listSync,
        BitrixRest $bitrixRestClient,
        BitrixStages $stages
    ): int {
        $this->info('Bitrix unit stays sync started...');
        $now = now();

        try {
            if ($this->option('sync-stages') !== '0') {
                $syncedStages = $stages->sync(
                    $bitrixRestClient,
                    UnitStayProfile::ENTITY_TYPE_ID,
                    'unit_stay',
                    'Unit stays',
                    $now
                );
                $this->info(sprintf('Unit stay stages synced: %d', $syncedStages));
            }

            $result = $listSync->sync(
                UnitStayProfile::ENTITY_TYPE_ID,
                'unit_stays',
                self::SELECT_FIELDS,
                UnitStay::class,
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
                $this->warn('Failed stay ids: '.implode(', ', $preview).(count($result['failed_ids']) > 30 ? '...' : ''));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Bitrix unit stays sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
