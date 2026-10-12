<?php

namespace App\Console\Commands;

use App\Jobs\MoveFailedLeadsJob;
use App\Services\MoveFailedLeads;
use Illuminate\Console\Command;
use Throwable;

class MoveFailedLeadsCommand extends Command
{
    protected $signature = 'bitrix:move-failed-leads
                            {--execute : Actually move deals (otherwise only count)}
                            {--sync : Run in-process loop until remaining is 0}
                            {--batch=15 : Deals per iteration (split into Bitrix chunks of 5)}
                            {--sleep-ms=0 : Optional pause after each Bitrix chunk}';

    protected $description = '[OPS] Move category 52 Qualified/Postponed leads (created 2026-01-01..2026-09-12) to Failed Leads.';

    /**
     * Execute the console command.
     */
    public function handle(MoveFailedLeads $service): int
    {
        $count = $service->count();
        $this->info(sprintf(
            'Matches: %d deals (category %d, stages Qualified+Postponed, DATE_CREATE %s .. %s) → %s',
            $count,
            MoveFailedLeads::CATEGORY_ID,
            MoveFailedLeads::DATE_FROM,
            MoveFailedLeads::DATE_TO,
            MoveFailedLeads::STAGE_FAILED
        ));

        if (! (bool) $this->option('execute')) {
            $this->warn('DRY-RUN. Pass --execute to move. Add --sync for in-process loop until 0.');

            return self::SUCCESS;
        }

        if ($count <= 0) {
            $this->info('Nothing to move.');

            return self::SUCCESS;
        }

        $batch = max(1, (int) $this->option('batch'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        if ((bool) $this->option('sync')) {
            return $this->runSync($service, $batch, $sleepMs);
        }

        MoveFailedLeadsJob::dispatch($batch, $sleepMs);
        $this->info(sprintf('Queued MoveFailedLeadsJob (batch=%d, sleep-ms=%d).', $batch, $sleepMs));

        return self::SUCCESS;
    }

    /**
     * Move deals in-process until Bitrix count is actually zero.
     */
    private function runSync(MoveFailedLeads $service, int $batch, int $sleepMs): int
    {
        $totalMoved = 0;
        $totalFailed = 0;
        $iteration = 0;
        $emptyStreak = 0;

        $this->info('Sync loop started — runs until remaining=0 (chunks of 5).');

        while (true) {
            $left = $service->count();
            if ($left <= 0) {
                $this->info(sprintf('Done. remaining=0 moved=%d failed=%d', $totalMoved, $totalFailed));

                return self::SUCCESS;
            }

            $iteration++;
            $started = microtime(true);

            try {
                $result = $service->moveBatch($batch, $sleepMs);
            } catch (Throwable $e) {
                $this->error(sprintf('Iteration %d failed: %s — retry in 15s (left=%d)', $iteration, $e->getMessage(), $left));
                sleep(15);

                continue;
            }

            $totalMoved += $result['moved'];
            $totalFailed += $result['failed'];
            $elapsed = round(microtime(true) - $started, 1);

            $this->line(sprintf(
                '#%d moved=%d failed=%d throttled=%s elapsed=%ss total_moved=%d total_failed=%d left=%d',
                $iteration,
                $result['moved'],
                $result['failed'],
                ! empty($result['throttled']) ? 'yes' : 'no',
                $elapsed,
                $totalMoved,
                $totalFailed,
                $left
            ));

            if (! empty($result['throttled'])) {
                $this->warn('Bitrix OPERATION_TIME_LIMIT — waiting 60s');
                $emptyStreak = 0;
                sleep(60);

                continue;
            }

            if ($result['moved'] > 0) {
                $emptyStreak = 0;

                continue;
            }

            $emptyStreak++;
            $wait = min(120, 10 * $emptyStreak);
            $this->warn(sprintf(
                'No progress (streak=%d, left=%d) — waiting %ds then retry',
                $emptyStreak,
                $left,
                $wait
            ));
            sleep($wait);
        }
    }
}
