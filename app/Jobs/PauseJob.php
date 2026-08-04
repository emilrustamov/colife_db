<?php

namespace App\Jobs;

use App\Services\PauseDates;
use App\Services\BitrixRest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class PauseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    /**
     * @param  array{event_token:string,domain:string,workflow_id?:string,document_id?:mixed,enqueued_at?:string,target_at?:string,wait_seconds?:int}  $data
     */
    public function __construct(public array $data) {}

    /**
     * Resume paused Bitrix business process activity.
     */
    public function handle(BitrixRest $bitrixRestClient, PauseDates $pauseDateResolver): void
    {
        $logger = Log::channel('bitrix_pauses');
        $now = now();
        $enqueuedAt = $this->data['enqueued_at'] ?? null;
        $targetAt = $this->data['target_at'] ?? null;
        $lagSeconds = null;

        if (is_string($enqueuedAt)) {
            $lagSeconds = max($now->diffInSeconds($enqueuedAt, false) * -1, 0);
        }

        $logger->info('PAUSE_JOB_START', [
            'job_id' => $this->job?->getJobId(),
            'attempt' => $this->attempts(),
            'domain' => $this->data['domain'],
            'event_token' => $this->data['event_token'],
            'workflow_id' => $this->data['workflow_id'] ?? null,
            'document_id' => $this->data['document_id'] ?? null,
            'enqueued_at' => $enqueuedAt,
            'target_at' => $targetAt,
            'wait_seconds' => $this->data['wait_seconds'] ?? null,
            'lag_seconds' => $lagSeconds,
            'server_now_portal' => $now->timezone($pauseDateResolver->portalTimezone())->toIso8601String(),
        ]);

        if ((bool) config('services.bitrix.pause_dry_run', false)) {
            $logger->info('PAUSE_RESUMED_DRY_RUN', [
                'domain' => $this->data['domain'],
                'event_token' => $this->data['event_token'],
            ]);

            return;
        }

        try {
            $response = $bitrixRestClient->postJson(
                'bizproc.event.send',
                [
                    'event_token' => $this->data['event_token'],
                    'return_values' => [],
                ],
                $this->data['domain']
            );

            $logger->info('PAUSE_RESUMED', [
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
                'domain' => $this->data['domain'],
                'event_token' => $this->data['event_token'],
                'workflow_id' => $this->data['workflow_id'] ?? null,
                'document_id' => $this->data['document_id'] ?? null,
                'lag_seconds' => $lagSeconds,
                'server_now_portal' => $now->timezone($pauseDateResolver->portalTimezone())->toIso8601String(),
                'response' => $response,
            ]);
        } catch (Throwable $e) {
            $logger->error('PAUSE_JOB_ERROR', [
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
                'domain' => $this->data['domain'],
                'event_token' => $this->data['event_token'],
                'workflow_id' => $this->data['workflow_id'] ?? null,
                'document_id' => $this->data['document_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $e): void
    {
        Log::channel('bitrix_pauses')->error('PAUSE_JOB_FAILED', [
            'job_id' => $this->job?->getJobId(),
            'attempts' => $this->attempts(),
            'domain' => $this->data['domain'],
            'event_token' => $this->data['event_token'],
            'workflow_id' => $this->data['workflow_id'] ?? null,
            'document_id' => $this->data['document_id'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}
