<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BackfillBitrixContactTimelineOldValuesCommand extends Command
{
    protected $signature = 'timeline:backfill-bitrix-contact-old-values
        {--limit=500 : Max number of latest events to inspect}
        {--days=14 : How many days back to scan}
        {--dry-run : Show what would be changed without writing}';

    protected $description = 'Backfill old_values for recent bitrix.contact.updated events';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $fromDate = now()->subDays($days);

        $candidates = $this->loadCandidates($fromDate, $limit);
        if ($candidates->isEmpty()) {
            $this->info('No candidate events found.');

            return self::SUCCESS;
        }

        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($candidates, $dryRun, &$updated, &$skipped): void {
            foreach ($candidates as $event) {
                $previousValues = $this->resolvePreviousNewValues($event);
                if ($previousValues === null) {
                    $skipped++;

                    continue;
                }

                if (! $dryRun) {
                    DB::table('activity_logs')
                        ->where('id', (string) $event->id)
                        ->update([
                            'old_values' => json_encode($previousValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                }

                $updated++;
            }
        });

        $this->info(sprintf(
            'Checked: %d, backfilled: %d, skipped: %d, mode: %s.',
            $candidates->count(),
            $updated,
            $skipped,
            $dryRun ? 'dry-run' : 'write'
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, object{id:string,subject_id:string,subject_type:string,happened_at:?string,created_at:string}>
     */
    private function loadCandidates(Carbon $fromDate, int $limit): Collection
    {
        return DB::table('activity_logs')
            ->select(['id', 'subject_id', 'subject_type', 'happened_at', 'created_at'])
            ->where('event', 'bitrix.contact.updated')
            ->whereNull('old_values')
            ->whereNotNull('new_values')
            ->where('created_at', '>=', $fromDate)
            ->orderByDesc('happened_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  object{id:string,subject_id:string,subject_type:string,happened_at:?string,created_at:string}  $event
     * @return array<string, mixed>|null
     */
    private function resolvePreviousNewValues(object $event): ?array
    {
        $happenedAt = $event->happened_at !== null ? (string) $event->happened_at : (string) $event->created_at;
        $createdAt = (string) $event->created_at;

        $previous = DB::table('activity_logs')
            ->select(['new_values'])
            ->where('subject_type', (string) $event->subject_type)
            ->where('subject_id', (string) $event->subject_id)
            ->whereNotNull('new_values')
            ->whereRaw(
                '(COALESCE(happened_at, created_at) < ? OR (COALESCE(happened_at, created_at) = ? AND created_at < ?))',
                [$happenedAt, $happenedAt, $createdAt]
            )
            ->orderByRaw('COALESCE(happened_at, created_at) DESC')
            ->orderByDesc('created_at')
            ->first();

        if ($previous === null || ! is_string($previous->new_values) || trim($previous->new_values) === '') {
            return null;
        }

        $decoded = json_decode($previous->new_values, true);
        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        return $decoded;
    }
}
