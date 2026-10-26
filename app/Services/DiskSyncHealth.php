<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class DiskSyncHealth
{
    public function __construct(
        private readonly TelegramAlert $alerts,
        private readonly TelegramAlertText $text,
        private readonly BitrixOAuth $oauth,
    ) {}

    /**
     * Scan recent bitrix_disk logs for sync failures and alert once per day.
     *
     * @return list<string>
     */
    public function check(): array
    {
        $hours = max(1, (int) config('services.telegram.disk_lookback_hours', 6));
        $since = now()->subHours($hours);
        $hasIssue = false;

        foreach ($this->logFiles() as $path) {
            $handle = fopen($path, 'r');
            if ($handle === false) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
                    continue;
                }

                try {
                    $at = Carbon::createFromFormat('Y-m-d H:i:s', $m[1], config('app.timezone'));
                } catch (\Throwable) {
                    continue;
                }

                if ($at === null || $at->lt($since)) {
                    continue;
                }

                if (str_contains($line, '.ERROR:')) {
                    $hasIssue = true;
                    break;
                }

                if (preg_match('/Disk sync completed (\{.*\})\s*$/', $line, $cm)) {
                    $payload = json_decode($cm[1], true);
                    if (is_array($payload) && (int) ($payload['failed'] ?? 0) > 0) {
                        $hasIssue = true;
                        break;
                    }
                }
            }

            fclose($handle);
            if ($hasIssue) {
                break;
            }
        }

        if (! $hasIssue) {
            return [];
        }

        $domain = $this->oauth->normalizeDomain((string) config('services.bitrix.portal_domain', ''));
        $message = $this->text->message(
            '💾',
            'Disk sync отвалился',
            $domain,
            'Проверь логи bitrix_disk и список 322.',
        );
        $this->alerts->notifyOnce('disk_sync_errors', $message);

        return [$message];
    }

    /**
     * @return list<string>
     */
    private function logFiles(): array
    {
        $dir = storage_path('logs');
        $files = glob($dir.'/bitrix_disk*.log') ?: [];
        rsort($files);

        return array_values(array_slice($files, 0, 4));
    }
}
