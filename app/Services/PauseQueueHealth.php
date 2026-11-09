<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class PauseQueueHealth
{
    public function __construct(
        private readonly TelegramAlert $alerts,
        private readonly TelegramAlertText $text,
        private readonly BitrixOAuth $oauth,
    ) {}

    /**
     * Check bp-pauses overdue/stuck jobs and recent failed_jobs.
     *
     * @return list<string>
     */
    public function check(): array
    {
        $messages = [];
        $now = now()->timestamp;
        $overdueBefore = $now - 120;

        $overdueDelayed = (int) Redis::zcount('queues:bp-pauses:delayed', '-inf', (string) $overdueBefore);
        $stuckReserved = (int) Redis::zcount('queues:bp-pauses:reserved', '-inf', (string) ($now - 180));
        $waiting = (int) Redis::llen('queues:bp-pauses');

        if ($overdueDelayed > 0 || $stuckReserved > 0 || $waiting > 50) {
            $message = $this->text->message(
                '⏸️',
                'Паузы отвалились · очередь стоит',
                null,
                'Проверь Horizon (bp-pauses). waiting='.$waiting.' overdue='.$overdueDelayed.' reserved='.$stuckReserved,
            );
            $this->alerts->notifyOnce('pause_queue_backlog', $message);
            $messages[] = $message;
        }

        $hours = max(1, (int) config('services.telegram.pause_failed_lookback_hours', 6));
        $failedRows = DB::table('failed_jobs')
            ->where('queue', 'bp-pauses')
            ->where('failed_at', '>=', now()->subHours($hours))
            ->get(['payload']);

        if ($failedRows->isNotEmpty()) {
            $domains = [];
            foreach ($failedRows as $row) {
                $domain = $this->extractDomain((string) $row->payload);
                $key = $domain !== '' ? $domain : 'unknown';
                $domains[$key] = true;
            }

            foreach (array_keys($domains) as $domain) {
                $portalDomain = $domain === 'unknown' ? null : $domain;
                $message = $this->text->message(
                    '❌',
                    'Паузы отвалились',
                    $portalDomain,
                    'Проверь OAuth и failed_jobs — BP могут зависнуть.',
                );
                $this->alerts->notifyOnce('pause_failed_jobs:'.$domain, $message);
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Extract portal domain from a queue/failed job JSON payload.
     */
    private function extractDomain(string $payload): string
    {
        $decoded = json_decode($payload, true);
        $command = is_array($decoded) ? (string) ($decoded['data']['command'] ?? '') : '';
        $haystack = $command !== '' ? $command : $payload;

        if (preg_match('/"domain";s:\d+:"([^"]+)"/', $haystack, $m)) {
            return $this->oauth->normalizeDomain($m[1]);
        }

        return '';
    }
}
