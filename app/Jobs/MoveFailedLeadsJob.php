<?php

namespace App\Jobs;

use App\Services\MoveFailedLeads;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class MoveFailedLeadsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 170;

    /**
     * @param  int  $batchSize  Deals per job (split into Bitrix chunks of 5)
     * @param  int  $sleepMs  Optional pause after each Bitrix chunk
     */
    public function __construct(
        public int $batchSize = 15,
        public int $sleepMs = 0,
    ) {}

    /**
     * Move one batch of leads and re-dispatch while matches remain.
     */
    public function handle(MoveFailedLeads $service): void
    {
        $result = $service->moveBatch($this->batchSize, $this->sleepMs);

        Log::info('MoveFailedLeads batch', [
            'moved' => $result['moved'],
            'failed' => $result['failed'],
            'remaining' => $result['remaining'],
            'throttled' => $result['throttled'] ?? false,
            'failed_ids' => $result['failed_ids'],
        ]);

        if ($result['remaining']) {
            $delay = ! empty($result['throttled']) ? 60 : 3;
            self::dispatch($this->batchSize, $this->sleepMs)->delay(now()->addSeconds($delay));
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('MoveFailedLeads job failed', [
            'error' => $exception?->getMessage(),
        ]);
    }
}
