<?php

namespace App\Listeners;

use App\Jobs\PauseJob;
use App\Jobs\SyncDiskJob;
use App\Services\TelegramAlert;
use App\Services\TelegramAlertText;
use Illuminate\Queue\Events\JobFailed;
use Throwable;

class NotifyTelegramOnJobFailed
{
    /**
     * Jobs that already send a dedicated alert from failed().
     *
     * @var list<class-string>
     */
    private const SELF_ALERTED = [
        PauseJob::class,
        SyncDiskJob::class,
    ];

    public function __construct(
        private readonly TelegramAlert $alerts,
        private readonly TelegramAlertText $text,
    ) {}

    /**
     * Notify Telegram when a queued job finally fails.
     */
    public function handle(JobFailed $event): void
    {
        try {
            $jobName = $event->job->resolveName();
            if (in_array($jobName, self::SELF_ALERTED, true)) {
                return;
            }

            $queue = $event->job->getQueue() ?: 'default';
            $exception = $event->exception;
            $error = $exception instanceof Throwable
                ? mb_substr($exception->getMessage(), 0, 300)
                : 'unknown error';

            $message = $this->text->message(
                '💥',
                'Job упал',
                null,
                $jobName.' · '.$queue."\n".$error,
            );

            $this->alerts->notifyOnce(
                'job_failed:'.$queue.':'.$jobName,
                $message,
                $this->alerts->instantTtl(),
            );
        } catch (Throwable) {
        }
    }
}
