<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TimelineCleanupCommand extends Command
{
    protected $signature = 'timeline:cleanup-bitrix-contact-noise
        {--limit=1000 : Max number of latest updated events to inspect}
        {--days=60 : How many days back to scan}
        {--dry-run : Show what would be deleted without writing}';

    protected $description = '[OPS one-off] Delete noisy bitrix.contact.updated timeline events with technical-only diffs. Not scheduled.';

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

        $idsToDelete = [];
        $skipped = 0;

        foreach ($candidates as $event) {
            $oldValues = $this->decodeValues($event->old_values);
            $newValues = $this->decodeValues($event->new_values);

            if ($oldValues === null || $newValues === null) {
                $skipped++;

                continue;
            }

            if (! $this->hasBusinessDiff($oldValues, $newValues)) {
                $idsToDelete[] = (string) $event->id;

                continue;
            }

            $skipped++;
        }

        $deleted = 0;
        if (! $dryRun && $idsToDelete !== []) {
            $deleted = DB::table('activity_logs')->whereIn('id', $idsToDelete)->delete();
        }

        $this->info(sprintf(
            'Checked: %d, noise found: %d, deleted: %d, skipped: %d, mode: %s.',
            $candidates->count(),
            count($idsToDelete),
            $dryRun ? 0 : $deleted,
            $skipped,
            $dryRun ? 'dry-run' : 'write'
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, object{id:string,old_values:mixed,new_values:mixed}>
     */
    private function loadCandidates(Carbon $fromDate, int $limit): Collection
    {
        return DB::table('activity_logs')
            ->select(['id', 'old_values', 'new_values'])
            ->where('event', 'bitrix.contact.updated')
            ->where('created_at', '>=', $fromDate)
            ->whereNotNull('old_values')
            ->whereNotNull('new_values')
            ->orderByDesc('happened_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeValues(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function hasBusinessDiff(array $oldValues, array $newValues): bool
    {
        $keys = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));

        foreach ($keys as $key) {
            if ($this->isTechnicalKey((string) $key)) {
                continue;
            }

            $old = $this->normalizeDiffValue($oldValues[$key] ?? null);
            $new = $this->normalizeDiffValue($newValues[$key] ?? null);
            if ($old !== $new) {
                return true;
            }
        }

        return false;
    }

    private function isTechnicalKey(string $key): bool
    {
        return in_array($key, [
            'bitrix_created_at',
            'bitrix_updated_at',
            'last_synced_at',
            'changed_by_bitrix_user_id',
            'changed_by_bitrix_user_name',
        ], true);
    }

    private function normalizeDiffValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }
}
