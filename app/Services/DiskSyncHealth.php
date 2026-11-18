<?php

namespace App\Services;

use App\Models\DiskSyncedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class DiskSyncHealth
{
    public function __construct(
        private readonly TelegramAlert $alerts,
        private readonly TelegramAlertText $text,
        private readonly BitrixOAuth $oauth,
    ) {}

    /**
     * Scan recent bitrix_disk logs for sync failures / long silence and alert once per day.
     *
     * @return list<string>
     */
    public function check(): array
    {
        $hours = max(1, (int) config('services.telegram.disk_lookback_hours', 6));
        $since = now()->subHours($hours);
        $hasIssue = false;
        $reason = 'Проверь логи bitrix_disk и список 322.';

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
            $staleDays = max(1, (int) config('services.telegram.disk_stale_days', 3));
            $lastSyncedAt = $this->lastSyncedAt();
            if ($lastSyncedAt !== null && $lastSyncedAt->lt(now()->subDays($staleDays))) {
                $hasIssue = true;
                $reason = 'Нет успешного disk sync уже '.$lastSyncedAt->diffForHumans()
                    .' (проверь /api/disk/pull auth и Bitrix webhook).';
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
            $reason,
        );
        $this->alerts->notifyOnce('disk_sync_errors', $message);

        return [$message];
    }

    private function lastSyncedAt(): ?Carbon
    {
        if (! Schema::hasTable('disk_synced_files')) {
            return null;
        }

        $value = DiskSyncedFile::query()->max('last_synced_at')
            ?? DiskSyncedFile::query()->max('updated_at');

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
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
