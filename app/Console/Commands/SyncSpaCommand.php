<?php

namespace App\Console\Commands;

use App\Models\Apartment;
use App\Models\ApartmentOwnership;
use App\Models\Portal;
use App\Models\Unit;
use App\Models\UnitStay;
use App\Services\BitrixListSync;
use App\Services\BitrixRest;
use App\Services\BitrixStages;
use App\Services\Profiles\ApartmentProfile;
use App\Services\Profiles\OwnershipProfile;
use App\Services\Profiles\UnitProfile;
use App\Services\Profiles\UnitStayProfile;
use App\Support\BitrixSync;
use Illuminate\Console\Command;
use Throwable;

class SyncSpaCommand extends Command
{
    protected $signature = 'bitrix:sync-spa
        {entity : apartments|units|apartment-ownerships|unit-stays}
        {--sync-stages=1}';

    protected $description = 'Sync a Bitrix SPA entity (apartments, units, ownerships, unit stays)';

    /**
     * Dispatch SPA list sync for the given entity key.
     */
    public function handle(
        BitrixListSync $listSync,
        BitrixRest $bitrixRestClient,
        BitrixStages $stages,
        BitrixSync $syncContext
    ): int {
        $entity = strtolower(trim((string) $this->argument('entity')));
        $config = $this->entityConfig($entity);
        if ($config === null) {
            $this->error('Unknown entity. Use: apartments, units, apartment-ownerships, unit-stays');

            return self::FAILURE;
        }

        $this->info($config['label'].' sync started...');
        $now = now();

        try {
            if ($this->option('sync-stages') !== '0') {
                $syncedStages = $stages->sync(
                    $bitrixRestClient,
                    $config['entity_type_id'],
                    $config['stage_key'],
                    $config['stage_label'],
                    $now
                );
                $this->info(sprintf('%s stages synced: %d', $config['stage_label'], $syncedStages));
            }

            $runner = function () use ($listSync, $config, $now): array {
                return $listSync->sync(
                    $config['entity_type_id'],
                    $config['entity_key'],
                    $config['select'],
                    $config['model'],
                    $now,
                    function (int $total, int $successful, int $failedCount): void {
                        $this->info(sprintf(
                            'Batch synced. Total processed: %d, successful: %d, failed: %d.',
                            $total,
                            $successful,
                            $failedCount
                        ));
                    },
                    $config['portal_id']
                );
            };

            $result = $config['force_upsert']
                ? $syncContext->forceUpsert($runner)
                : $runner();

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
                $this->warn('Failed ids: '.implode(', ', $preview).(count($result['failed_ids']) > 30 ? '...' : ''));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($config['label'].' sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array{
     *     label: string,
     *     entity_type_id: int,
     *     entity_key: string,
     *     stage_key: string,
     *     stage_label: string,
     *     model: class-string,
     *     select: list<string>,
     *     portal_id: int|null,
     *     force_upsert: bool
     * }|null
     */
    private function entityConfig(string $entity): ?array
    {
        return match ($entity) {
            'apartments' => [
                'label' => 'Bitrix apartments',
                'entity_type_id' => ApartmentProfile::ENTITY_TYPE_ID,
                'entity_key' => 'apartments',
                'stage_key' => 'apartment',
                'stage_label' => 'Apartments',
                'model' => Apartment::class,
                'select' => [
                    'id', 'title', 'stageId', 'contactId', 'createdTime', 'updatedTime',
                    'ufCrm6_1682232363193', 'ufCrm6_1707234311507', 'ufCrm6_1718821717',
                    'ufCrm6_1682232863625', 'ufCrm6_1682233481671', 'ufCrm6_1682232312628',
                    'ufCrm6_1682238902770', 'ufCrm6_1689258811986', 'ufCrm6Adresslink',
                    'ufCrm6_1697722222483', 'ufCrm6_1724248916', 'ufCrm6_1724247642',
                    'ufCrm6_1682235809295', 'ufCrm6_1686728251990', 'ufCrm6_1715776920',
                    'ufCrm6_1715777017', 'ufCrm6_1715777650', 'ufCrm6_1715777670',
                    'ufCrm6_1720794204', 'ufCrm6_1721897304', 'ufCrm6_1736951470242',
                    'ufCrm6_1753278068179', 'ufCrm6_1682238927645', 'ufCrm6_1683299159437',
                    'ufCrm6_1785314487770',
                ],
                'portal_id' => Portal::idByCode('ae'),
                'force_upsert' => true,
            ],
            'units' => [
                'label' => 'Bitrix units directory',
                'entity_type_id' => UnitProfile::ENTITY_TYPE_ID,
                'entity_key' => 'units',
                'stage_key' => 'unit',
                'stage_label' => 'Units',
                'model' => Unit::class,
                'select' => [
                    'id', 'title', 'stageId', 'createdTime', 'updatedTime',
                    'ufCrm8_1684429208', 'ufCrm8_1698838056004', 'ufCrm6_1707234311507',
                ],
                'portal_id' => Portal::idByCode('ae'),
                'force_upsert' => true,
            ],
            'apartment-ownerships', 'ownerships' => [
                'label' => 'Bitrix apartment ownerships',
                'entity_type_id' => OwnershipProfile::ENTITY_TYPE_ID,
                'entity_key' => 'apartment_ownerships',
                'stage_key' => 'apartment_ownership',
                'stage_label' => 'Apartment ownerships',
                'model' => ApartmentOwnership::class,
                'select' => [
                    'id', 'title', 'stageId', 'createdTime', 'updatedTime', 'parentId144',
                    'ufCrm10_1693823301', 'ufCrm10_1693823247516', 'ufCrm10_1693823282826',
                    'ufCrm10_1708956056', 'ufCrm10_1708955996', 'ufCrm10_1714652654',
                    'ufCrm10_1714652687', 'ufCrm10TerminationDate', 'ufCrm10_1727422412',
                ],
                'portal_id' => null,
                'force_upsert' => false,
            ],
            'unit-stays', 'stays' => [
                'label' => 'Bitrix unit stays',
                'entity_type_id' => UnitStayProfile::ENTITY_TYPE_ID,
                'entity_key' => 'unit_stays',
                'stage_key' => 'unit_stay',
                'stage_label' => 'Unit stays',
                'model' => UnitStay::class,
                'select' => [
                    'id', 'title', 'stageId', 'createdTime', 'updatedTime', 'contactId',
                    'opportunity', 'currencyId', 'parentId2', 'parentId167',
                    'ufCrm20_1693919019', 'ufCrm20_1700215654', 'ufCrm20Deal',
                    'ufCrm20_1693561495', 'ufCrm20TypeOfDeal', 'ufCrm20TypeOfPayment',
                    'ufCrm20_1744800159', 'ufCrm20_1744800193', 'ufCrm20HowManyMonths',
                    'ufCrm20RentalPrice', 'ufCrm20Deposit', 'ufCrm20TotalContractAmount',
                    'ufCrm20_1696523391', 'ufCrm20ContractStartDate', 'ufCrm20ContractEndDate',
                ],
                'portal_id' => null,
                'force_upsert' => false,
            ],
            default => null,
        };
    }
}
