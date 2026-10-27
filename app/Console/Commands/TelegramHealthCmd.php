<?php

namespace App\Console\Commands;

use App\Services\AppHttpHealth;
use App\Services\BitrixOAuth;
use App\Services\BitrixOAuthHealth;
use App\Services\DiskSyncHealth;
use App\Services\PauseQueueHealth;
use App\Services\SupervisorHealth;
use App\Services\TelegramAlertText;
use App\Services\TelegramBot;
use Illuminate\Console\Command;

class TelegramHealthCmd extends Command
{
    protected $signature = 'telegram:health {--demo : Send sample alert texts for every check type}';

    protected $description = 'Check OAuth, disk, pauses queue, supervisor, /up; alert Telegram on critical issues';

    /**
     * Run health checks and send Telegram alerts when needed.
     */
    public function handle(
        BitrixOAuthHealth $oauthHealth,
        DiskSyncHealth $diskHealth,
        PauseQueueHealth $pauseHealth,
        SupervisorHealth $supervisorHealth,
        AppHttpHealth $httpHealth,
        TelegramBot $bot,
        TelegramAlertText $text,
        BitrixOAuth $oauth,
    ): int {
        if ($this->option('demo')) {
            return $this->sendDemo($bot, $text, $oauth);
        }

        $issues = array_merge(
            $oauthHealth->check(),
            $diskHealth->check(),
            $pauseHealth->check(),
            $supervisorHealth->check(),
            $httpHealth->check(),
        );

        if ($issues === []) {
            $this->info('Health checks OK (no Telegram alerts).');

            return self::SUCCESS;
        }

        foreach ($issues as $message) {
            $this->warn(strip_tags($message));
        }

        $this->info('Health checks finished with issues.');

        return self::SUCCESS;
    }

    /**
     * Send one sample message per alert type so texts can be reviewed in Telegram.
     */
    private function sendDemo(TelegramBot $bot, TelegramAlertText $text, BitrixOAuth $oauth): int
    {
        if (! $bot->enabled()) {
            $this->error('Telegram alerts disabled or TELEGRAM_* empty.');

            return self::FAILURE;
        }

        $portals = $oauth->configuredPortals();
        $ae = $portals['AE'] ?? 'colifeae.bitrix24.eu';
        $hk = $portals['HK'] ?? 'colifepacific.bitrix24.com';
        $appUrl = rtrim((string) config('app.url'), '/');

        $samples = [
            $text->message('🔐', 'OAuth отвалился', $hk, "Переустанови приложение: {$appUrl}/api/bp/install"),
            $text->message('💾', 'Disk sync отвалился', $ae, 'Проверь логи bitrix_disk и список 322.'),
            $text->message('⏸️', 'Паузы отвалились · очередь стоит', null, 'Проверь supervisor laravel-queue-pauses.'),
            $text->message('❌', 'Паузы отвалились', $hk, 'Проверь OAuth и failed_jobs — BP могут зависнуть.'),
            $text->message('⏸️', 'Паузы: wait отклонён', $hk, 'Bitrix шлёт wait, сервер отвечает 403 — BP зависают на паузе.'),
            $text->message('❌', 'Паузы: job упал', $hk, 'Bitrix OAuth refresh failed.'),
            $text->message('⚙️', 'Воркеры очереди отвалились', null, 'Паузы и фоновые job не обрабатываются.'),
            $text->message('🌐', 'API отвалился', null, 'Bitrix не достучится до пауз и вебхуков.'),
        ];

        $sent = 0;
        foreach ($samples as $i => $sample) {
            $ok = $bot->send('<i>DEMO '.($i + 1).'/'.count($samples)."</i>\n".$sample);
            if (! $ok) {
                $this->error('Failed to send demo #'.($i + 1));

                return self::FAILURE;
            }
            $sent++;
            $this->line('sent demo #'.$sent);
        }

        $this->info("Demo: {$sent} sample alerts sent.");

        return self::SUCCESS;
    }
}
