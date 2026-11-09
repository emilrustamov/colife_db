<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class RecoverPausesCommand extends Command
{
    protected $signature = 'bitrix:recover-stuck-pauses';

    protected $description = 'Log overdue/stuck Redis pause queue jobs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $logger = Log::channel('bitrix_pauses');
        $now = now()->timestamp;
        $overdueBefore = $now - 120;
        $stuckReservedBefore = $now - 180;

        $overdueDelayed = (int) Redis::zcount('queues:bp-pauses:delayed', '-inf', (string) $overdueBefore);
        $stuckReserved = (int) Redis::zcount('queues:bp-pauses:reserved', '-inf', (string) $stuckReservedBefore);
        $waiting = (int) Redis::llen('queues:bp-pauses');

        if ($overdueDelayed > 0 || $stuckReserved > 0) {
            $logger->error('PAUSE_RECOVERY_RUN', [
                'waiting' => $waiting,
                'overdue_delayed' => $overdueDelayed,
                'stuck_reserved' => $stuckReserved,
            ]);
        }

        $this->info("waiting={$waiting} overdue_delayed={$overdueDelayed} stuck_reserved={$stuckReserved}");

        return self::SUCCESS;
    }
}
