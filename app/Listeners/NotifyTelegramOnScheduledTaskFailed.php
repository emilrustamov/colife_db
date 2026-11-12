<?php

namespace App\Listeners;

use App\Services\TelegramAlert;
use App\Services\TelegramAlertText;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Throwable;

class NotifyTelegramOnScheduledTaskFailed
{
    public function __construct(
        private readonly TelegramAlert $alerts,
        private readonly TelegramAlertText $text,
    ) {}

    /**
     * Notify Telegram when a scheduled artisan command fails.
     */
    public function handle(ScheduledTaskFailed $event): void
    {
        try {
            $command = $this->commandName($event);
            $error = $event->exception instanceof Throwable
                ? mb_substr($event->exception->getMessage(), 0, 300)
                : 'exit non-zero';

            $message = $this->text->message(
                '⏰',
                'Schedule failed',
                null,
                $command."\n".$error,
            );

            $this->alerts->notifyOnce(
                'schedule_failed:'.$command,
                $message,
                (int) config('services.telegram.dedupe_ttl', 86400)
            );
        } catch (Throwable) {
        }
    }

    /**
     * Resolve a short command name for dedupe and message text.
     */
    private function commandName(ScheduledTaskFailed $event): string
    {
        $description = trim((string) $event->task->command);
        if ($description === '') {
            $description = trim((string) $event->task->description);
        }

        if (preg_match("/artisan'\s+'([^']+)'/", $description, $m)) {
            return $m[1];
        }

        if (preg_match('/artisan\s+(\S+)/', $description, $m)) {
            return $m[1];
        }

        return $description !== '' ? $description : 'unknown';
    }
}
