<?php

namespace App\Jobs;

use App\Services\DealSourceFromMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOpenLineMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

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
    public function handle(DealSourceFromMessage $service): void
    {
        $service->handle($this->domain, $this->packet);
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
