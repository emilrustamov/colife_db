<?php

namespace App\Listeners;

use App\Services\TelegramAlert;
use App\Services\TelegramAlertText;
use Illuminate\Queue\Events\JobFailed;
use Throwable;

class NotifyTelegramOnJobFailed
{
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
                (int) config('services.telegram.instant_dedupe_ttl', 3600)
            );
        } catch (Throwable) {
        }
    }
}
