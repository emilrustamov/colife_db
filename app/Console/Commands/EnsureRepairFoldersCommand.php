<?php

namespace App\Console\Commands;

use App\Services\EnsureApartmentRepairFolders;
use Illuminate\Console\Command;
use Throwable;

class EnsureRepairFoldersCommand extends Command
{
    protected $signature = 'bitrix:ensure-repair-folders
                            {--execute : Create folders and write Repair ID to apartment cards}
                            {--disk-links : Write Repair folder URL into Repair SPA (type 166)}
                            {--id= : Process a single apartment SPA item ID}
                            {--sleep-ms=200 : Pause between apartments}';

    protected $description = '[OPS] Create Repair/Photo/Estimate/Invoices on apartment Disk folders. Dry-run by default.';

    /**
     * Execute the console command.
     */
    public function handle(EnsureApartmentRepairFolders $service): int
    {
        $execute = (bool) $this->option('execute');
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $onlyId = $this->option('id');
        $onlyApartmentId = is_numeric($onlyId) ? (int) $onlyId : null;

        $this->info($execute ? 'EXECUTE mode' : 'DRY-RUN mode (pass --execute to write)');

        if ((bool) $this->option('disk-links')) {
            return $this->handleDiskLinks($service, $execute, $sleepMs, $onlyApartmentId);
        }

        $items = $service->listApartments();
        if ($onlyApartmentId !== null) {
            $items = array_values(array_filter(
                $items,
                fn (array $item): bool => (int) ($item['id'] ?? 0) === $onlyApartmentId
            ));
        }

        $this->info('apartments='.count($items));

        $createdRepair = 0;
        $createdChildren = 0;
        $updatedCards = 0;
        $skippedInactive = 0;
        $skippedNoFolder = 0;
        $failed = 0;

        foreach ($items as $item) {
            try {
                $result = $service->ensure($item, $execute);
            } catch (Throwable $e) {
                $failed++;
                $this->error('FAIL '.($item['id'] ?? '?').' '.$e->getMessage());

                continue;
            }

            if ($result['skipped']) {
                if ($result['skip_reason'] === 'inactive_stage') {
                    $skippedInactive++;
                } else {
                    $skippedNoFolder++;
                }

                continue;
            }

            if ($result['created_repair']) {
                $createdRepair++;
            }
            $createdChildren += count($result['created_children']);
            if ($result['updated_card']) {
                $updatedCards++;
            }

            $this->line(sprintf(
                '%s apt=%d folder=%d repair=%s created_repair=%s children=%s card=%s %s',
                $execute ? 'OK' : 'PLAN',
                $result['apartment_id'],
                $result['folder_id'],
                $result['repair_id'] ?? '-',
                $result['created_repair'] ? 'yes' : 'no',
                $result['created_children'] === [] ? '-' : implode(',', $result['created_children']),
                $result['updated_card'] ? 'yes' : 'no',
                $result['title']
            ));

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info(sprintf(
            'done. created_repair=%d created_children=%d updated_cards=%d skipped_inactive=%d skipped_no_folder=%d failed=%d',
            $createdRepair,
            $createdChildren,
            $updatedCards,
            $skippedInactive,
            $skippedNoFolder,
            $failed
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Fill Repair SPA disk URL from apartment Repair folder id.
     */
    private function handleDiskLinks(
        EnsureApartmentRepairFolders $service,
        bool $execute,
        int $sleepMs,
        ?int $onlyApartmentId
    ): int {
        $items = $service->listApartments();
        if ($onlyApartmentId !== null) {
            $items = array_values(array_filter(
                $items,
                fn (array $item): bool => (int) ($item['id'] ?? 0) === $onlyApartmentId
            ));
        }

        $this->info('apartments='.count($items));

        $updated = 0;
        $skippedInactive = 0;
        $skippedOther = 0;
        $failed = 0;

        foreach ($items as $item) {
            try {
                $result = $service->fillRepairSpaDiskUrl($item, $execute);
            } catch (Throwable $e) {
                $failed++;
                $this->error('FAIL '.($item['id'] ?? '?').' '.$e->getMessage());

                continue;
            }

            if ($result['skipped']) {
                if ($result['skip_reason'] === 'inactive_stage') {
                    $skippedInactive++;
                } else {
                    $skippedOther++;
                }

                continue;
            }

            $updated++;
            $this->line(sprintf(
                '%s apt=%d repair_spa=%s url=%s %s',
                $execute ? 'OK' : 'PLAN',
                $result['apartment_id'],
                $result['repair_spa_id'] ?? '-',
                $result['url'] ?? '-',
                $result['title']
            ));

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info(sprintf(
            'disk-links done. updated=%d skipped_inactive=%d skipped_other=%d failed=%d',
            $updated,
            $skippedInactive,
            $skippedOther,
            $failed
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
