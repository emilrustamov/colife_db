<?php

namespace App\Listeners;

use App\Services\TelegramAlert;
use App\Services\TelegramAlertText;
use Laravel\Horizon\Events\LongWaitDetected;
use Throwable;

class NotifyTelegramOnLongWait
{
    public function __construct(
        private readonly TelegramAlert $alerts,
        private readonly TelegramAlertText $text,
    ) {}

    /**
     * Notify Telegram when Horizon detects a long queue wait.
     */
    public function handle(LongWaitDetected $event): void
    {
        try {
            $message = $this->text->message(
                '⏳',
                'Очередь тормозит',
                null,
                $event->queue.' · wait '.$event->seconds.'s',
            );

            $this->alerts->notifyOnce(
                'horizon_long_wait:'.$event->queue,
                $message,
                $this->alerts->instantTtl(),
            );
        } catch (Throwable) {
        }
    }
}
