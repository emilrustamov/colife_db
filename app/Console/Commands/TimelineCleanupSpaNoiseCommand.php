<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TimelineCleanupSpaNoiseCommand extends Command
{
    protected $signature = 'timeline:cleanup-bitrix-spa-noise
        {--chunk=5000 : Rows to delete per batch}
        {--dry-run : Count candidates without deleting}
        {--skip-duplicates : Do not remove duplicate *.created events}';

    protected $description = '[OPS one-off] Delete SPA timeline updates with incomplete old_values (bitrix_id-only) and duplicate *.created events. Not scheduled.';

    /**
     * @var list<string>
     */
    private const UPDATED_EVENTS = [
        'bitrix.apartment.updated',
        'bitrix.unit.updated',
        'bitrix.apartment_ownership.updated',
        'bitrix.unit_stay.updated',
    ];

    /**
     * @var list<string>
     */
    private const CREATED_EVENTS = [
        'bitrix.apartment.created',
        'bitrix.unit.created',
        'bitrix.apartment_ownership.created',
        'bitrix.unit_stay.created',
    ];

    public function handle(): int
    {
        $chunk = max(100, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $skipDuplicates = (bool) $this->option('skip-duplicates');

        $noiseDeleted = $this->cleanupIncompleteOldValues($chunk, $dryRun);
        $duplicateDeleted = 0;
        if (! $skipDuplicates) {
            $duplicateDeleted = $this->cleanupDuplicateCreates($chunk, $dryRun);
        }

        $this->info(sprintf(
            'SPA noise %s: %d, duplicate created %s: %d, mode: %s.',
            $dryRun ? 'found' : 'deleted',
            $noiseDeleted,
            $dryRun ? 'found' : 'deleted',
            $duplicateDeleted,
            $dryRun ? 'dry-run' : 'write'
        ));

        return self::SUCCESS;
    }

    private function cleanupIncompleteOldValues(int $chunk, bool $dryRun): int
    {
        $total = 0;

        foreach (self::UPDATED_EVENTS as $event) {
            if ($dryRun) {
                $eventTotal = $this->countIncompleteForEvent($event, $chunk);
                $total += $eventTotal;
                $this->line(sprintf('%s: incomplete old_values %d.', $event, $eventTotal));

                continue;
            }

            $eventTotal = 0;
            while (true) {
                $ids = DB::table('activity_logs')
                    ->where('event', $event)
                    ->where('old_values', 'like', '{"bitrix_id":%')
                    ->where('old_values', 'not like', '%,%')
                    ->limit($chunk)
                    ->pluck('id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->all();

                if ($ids === []) {
                    break;
                }

                $deleted = DB::table('activity_logs')->whereIn('id', $ids)->delete();
                $eventTotal += $deleted;
                $total += $deleted;
                $this->line(sprintf('%s: deleted batch %d (running %d).', $event, $deleted, $eventTotal));
            }

            $this->line(sprintf('%s: done, deleted %d.', $event, $eventTotal));
        }

        return $total;
    }

    private function countIncompleteForEvent(string $event, int $chunk): int
    {
        $total = 0;
        $afterId = null;

        while (true) {
            $query = DB::table('activity_logs')
                ->select(['id'])
                ->where('event', $event)
                ->where('old_values', 'like', '{"bitrix_id":%')
                ->where('old_values', 'not like', '%,%')
                ->orderBy('id')
                ->limit($chunk);

            if ($afterId !== null) {
                $query->where('id', '>', $afterId);
            }

            $ids = $query->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            $total += $ids->count();
            $afterId = (string) $ids->last();
        }

        return $total;
    }

    private function cleanupDuplicateCreates(int $chunk, bool $dryRun): int
    {
        $groups = DB::table('activity_logs')
            ->selectRaw('subject_type, subject_id, event, happened_at, COUNT(*) as c, MIN(id) as keep_id')
            ->whereIn('event', self::CREATED_EVENTS)
            ->groupBy('subject_type', 'subject_id', 'event', 'happened_at')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($groups->isEmpty()) {
            $this->line('Duplicate created events: none.');

            return 0;
        }

        $idsToDelete = [];
        foreach ($groups as $group) {
            $duplicates = DB::table('activity_logs')
                ->select(['id'])
                ->where('subject_type', $group->subject_type)
                ->where('subject_id', $group->subject_id)
                ->where('event', $group->event)
                ->where('happened_at', $group->happened_at)
                ->where('id', '!=', $group->keep_id)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            foreach ($duplicates as $id) {
                $idsToDelete[] = $id;
            }
        }

        $found = count($idsToDelete);
        $this->line(sprintf('Duplicate created events: groups %d, extras %d.', $groups->count(), $found));

        if ($dryRun || $idsToDelete === []) {
            return $found;
        }

        $deleted = 0;
        foreach (array_chunk($idsToDelete, $chunk) as $batch) {
            $deleted += DB::table('activity_logs')->whereIn('id', $batch)->delete();
        }

        return $deleted;
    }
}
