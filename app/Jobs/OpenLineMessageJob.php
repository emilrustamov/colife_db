<?php

namespace App\Jobs;

use App\Exceptions\OlDealNotReadyException;
use App\Services\OlDealSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenLineMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 6;

    public int $timeout = 60;

    /**
     * Initial delay before first attempt (seconds) — chat→deal link usually ready by then.
     */
    public const INITIAL_DELAY_SECONDS = 60;

    /**
     * Delay between NO_DEAL retries (seconds).
     */
    public const RETRY_DELAY_SECONDS = 60;

    /**
     * @param  array<string, mixed>  $packet
     */
    public function __construct(
        public string $domain,
        public array $packet
    ) {}

    /**
     * Apply deal SOURCE_ID from Open Lines message text.
     */
    public function handle(OlDealSource $service): void
    {
        try {
            $service->handle($this->domain, $this->packet);
        } catch (OlDealNotReadyException $e) {
            if ($this->attempts() < $this->tries) {
                Log::channel('bitrix_open_lines')->info('OL_SOURCE_NO_DEAL_RETRY', [
                    'chat_id' => (int) data_get($this->packet, 'chat.id', 0),
                    'attempt' => $this->attempts(),
                    'release_in' => self::RETRY_DELAY_SECONDS,
                ]);
                $this->release(self::RETRY_DELAY_SECONDS);

                return;
            }

            Log::channel('bitrix_open_lines')->warning('OL_SOURCE_NO_DEAL_GAVE_UP', [
                'chat_id' => (int) data_get($this->packet, 'chat.id', 0),
                'attempts' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log permanent failures without retrying forever.
     */
    public function failed(?Throwable $exception): void
    {
        Log::channel('bitrix_open_lines')->error('OL_SOURCE_JOB_FAILED', [
            'domain' => $this->domain,
            'chat_id' => (int) data_get($this->packet, 'chat.id', 0),
            'message_id' => (int) data_get($this->packet, 'message.id', 0),
            'error' => $exception?->getMessage(),
        ]);
    }
}
