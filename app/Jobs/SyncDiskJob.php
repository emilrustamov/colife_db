<?php

namespace App\Jobs;

use App\Services\DiskSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncDiskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    /**
     * @param  int  $listId  Bitrix universal list IBLOCK_ID
     * @param  int|null  $elementId  Optional single list element ID
     */
    public function __construct(
        public int $listId,
        public ?int $elementId = null,
    ) {}

    /**
     * Run disk file sync for the given list.
     */
    public function handle(DiskSync $service): void
    {
        $logger = Log::channel('bitrix_disk');
        $logger->info('Disk sync job started', [
            'list_id' => $this->listId,
            'element_id' => $this->elementId,
            'attempt' => $this->attempts(),
        ]);

        $result = $service->sync($this->listId, $this->elementId);

        $logger->info('Disk sync job finished', $result);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::channel('bitrix_disk')->error('Disk sync job failed', [
            'list_id' => $this->listId,
            'element_id' => $this->elementId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
