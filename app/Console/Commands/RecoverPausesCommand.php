<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecoverPausesCommand extends Command
{
    protected $signature = 'bitrix:recover-stuck-pauses';

    protected $description = 'Release overdue pause queue jobs and log stuck pauses';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $logger = Log::channel('bitrix_pauses');
        $now = now()->timestamp;
        $overdueBefore = $now - 120;
        $stuckReservedBefore = $now - 180;

        $overdue = DB::table('jobs')
            ->where('queue', 'bp-pauses')
            ->where('available_at', '<=', $overdueBefore)
            ->whereNull('reserved_at')
            ->get(['id', 'payload', 'available_at', 'attempts', 'created_at']);

        foreach ($overdue as $job) {
            $meta = $this->extractPauseMeta((string) $job->payload);
            $logger->warning('PAUSE_OVERDUE', [
                'job_id' => $job->id,
                'attempts' => $job->attempts,
                'available_at' => date('c', (int) $job->available_at),
                'overdue_seconds' => $now - (int) $job->available_at,
                'created_at' => date('c', (int) $job->created_at),
            ] + $meta);
        }

        $stuckReserved = DB::table('jobs')
            ->where('queue', 'bp-pauses')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<=', $stuckReservedBefore)
            ->get(['id', 'payload', 'available_at', 'reserved_at', 'attempts']);

        $released = 0;
        foreach ($stuckReserved as $job) {
            $meta = $this->extractPauseMeta((string) $job->payload);
            $logger->warning('PAUSE_STUCK_RESERVED', [
                'job_id' => $job->id,
                'attempts' => $job->attempts,
                'reserved_at' => date('c', (int) $job->reserved_at),
                'reserved_seconds' => $now - (int) $job->reserved_at,
            ] + $meta);

            DB::table('jobs')
                ->where('id', $job->id)
                ->update(['reserved_at' => null]);

            $released++;
        }

        if ($overdue->isNotEmpty() || $released > 0) {
            $logger->error('PAUSE_RECOVERY_RUN', [
                'overdue_waiting' => $overdue->count(),
                'released_reserved' => $released,
            ]);
        }

        $this->info("overdue={$overdue->count()} released={$released}");

        return self::SUCCESS;
    }

    /**
     * @return array{event_token: ?string, domain: ?string, workflow_id: ?string, document_id: ?string}
     */
    private function extractPauseMeta(string $payload): array
    {
        $eventToken = null;
        $domain = null;
        $workflowId = null;
        $documentId = null;

        if (preg_match('/"event_token";s:\d+:"([^"]+)"/', $payload, $m)) {
            $eventToken = $m[1];
        }
        if (preg_match('/"domain";s:\d+:"([^"]+)"/', $payload, $m)) {
            $domain = $m[1];
        }
        if (preg_match('/"workflow_id";s:\d+:"([^"]*)"/', $payload, $m)) {
            $workflowId = $m[1] !== '' ? $m[1] : null;
        }
        if (preg_match('/"document_id";/', $payload)) {
            $documentId = 'present';
        }

        return [
            'event_token' => $eventToken,
            'domain' => $domain,
            'workflow_id' => $workflowId,
            'document_id' => $documentId,
        ];
    }
}
