<?php

namespace App\Jobs;

use App\Services\BitrixPauseDateResolver;
use App\Services\BitrixRestClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPauseJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $uniqueFor = 604800;

    /**
     * @param  array{event_token:string,domain:string}  $data
     */
    public function __construct(public array $data) {}

    /**
     * Get the unique ID for the job lock.
     */
    public function uniqueId(): string
    {
        return $this->data['event_token'];
    }

    /**
     * Resume paused Bitrix business process activity.
     */
    public function handle(BitrixRestClient $bitrixRestClient, BitrixPauseDateResolver $pauseDateResolver): void
    {
        if ((bool) config('services.bitrix.pause_dry_run', false)) {
            Log::info('PAUSE_RESUMED_DRY_RUN', [
                'domain' => $this->data['domain'],
                'event_token' => $this->data['event_token'],
            ]);

            return;
        }

        $response = $bitrixRestClient->postJson(
            'bizproc.event.send',
            [
                'event_token' => $this->data['event_token'],
                'return_values' => [],
            ],
            $this->data['domain']
        );

        Log::info('PAUSE_RESUMED', [
            'domain' => $this->data['domain'],
            'event_token' => $this->data['event_token'],
            'server_now_portal' => now()->timezone($pauseDateResolver->portalTimezone())->toIso8601String(),
            'response' => $response,
        ]);
    }
}
