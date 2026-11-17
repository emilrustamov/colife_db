<?php

namespace App\Listeners;

use App\Services\TelegramAlert;
use App\Services\TelegramAlertText;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Throwable;

class NotifyTelegramOnScheduledTaskFailed
{
    /**
     * Commands that already send a detailed CollectFailureAlert / sync alert.
     *
     * @var list<string>
     */
    private const SELF_ALERTED = [
        'bitrix:sync-contacts',
        'bitrix:sync-spa',
        'bitrix:sync-apartments',
        'bitrix:sync-units',
        'bitrix:sync-apartment-ownerships',
        'bitrix:sync-unit-stays',
        'bitrix:sync-disk',
        'chatapp:collect',
        'twilio:collect',
        'vdsina:collect',
        'wazzup:collect',
        'wikibot:collect',
        'payments:collect',
        'alma:collect',
        'landlord-finance:collect',
    ];

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
            if ($this->alreadyAlerted($command)) {
                return;
            }

            $exitCode = $event->task->exitCode;
            $error = is_int($exitCode)
                ? 'exit code '.$exitCode
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
                $this->alerts->dailyTtl(),
            );
        } catch (Throwable) {
        }
    }

    /**
     * Whether a detailed failure alert was already sent by the command itself.
     */
    private function alreadyAlerted(string $command): bool
    {
        $base = strtok($command, ' ') ?: $command;

        return in_array($base, self::SELF_ALERTED, true);
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

        if (preg_match("/artisan'?\s+'?([^\s']+)/", $description, $m)) {
            return $m[1];
        }

        if (preg_match('/artisan\s+(\S+)/', $description, $m)) {
            return rtrim($m[1], "'\"");
        }

        return $description !== '' ? $description : 'unknown';
    }
}
