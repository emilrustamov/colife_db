<?php

namespace App\Jobs;

use App\Models\LandlordFinanceSheet;
use App\Services\AlmaApi;
use App\Services\CollectFailureAlert;
use App\Services\GoogleSheetsApi;
use App\Services\LandlordFinanceIdResolver;
use App\Services\LandlordFinanceSheetParser;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class CollectLandlordFinance
{
    use Dispatchable;

    /**
     * @param  list<int>|null  $years
     */
    public function __construct(
        private readonly ?int $registryId = null,
        private readonly ?array $years = null,
        private readonly bool $dryRun = false,
        private readonly bool $notifyOnFailure = true,
    ) {}

    /**
     * Read Google Sheets landlord reports and push month batches to Alma.
     */
    public function handle(
        GoogleSheetsApi $sheetsApi,
        LandlordFinanceSheetParser $parser,
        AlmaApi $almaApi,
        LandlordFinanceIdResolver $idResolver,
        CollectFailureAlert $collectFailureAlert,
    ): void {
        $logger = Log::channel('landlord_finance');
        $credentialsPath = trim((string) config('services.landlord_finance.credentials_path'));

        if ($credentialsPath === '' || ! is_file($credentialsPath)) {
            $logger->warning('Landlord finance collect skipped: Google credentials are not configured.');

            return;
        }

        $query = LandlordFinanceSheet::query()->active()->orderBy('id');
        if ($this->registryId !== null) {
            $query->whereKey($this->registryId);
        }

        $entries = $query->get();
        if ($entries->isEmpty()) {
            $logger->info('Landlord finance collect skipped: registry is empty.');

            return;
        }

        $expanded = collect();
        foreach ($entries as $entry) {
            foreach ($this->expandEntry($entry, $sheetsApi, $idResolver, $logger) as $target) {
                $key = $target->spreadsheet_id.'|'.trim((string) ($target->sheet_name ?? ''));
                $expanded->put($key, $target);
            }
        }
        $expanded = $expanded->values();

        if ($expanded->isEmpty()) {
            $logger->info('Landlord finance collect skipped: no sync targets.');

            return;
        }

        $rowsConfig = (array) config('services.landlord_finance.rows');
        $startRow = (int) min($rowsConfig);
        $endRow = (int) max($rowsConfig);
        $delayMs = max(0, (int) config('services.landlord_finance.request_delay_ms', 200));

        $ok = 0;
        $failed = 0;
        $processedItems = 0;
        $skippedItems = 0;
        /** @var list<LandlordFinanceSheet> $quotaRetry */
        $quotaRetry = [];

        try {
            foreach ($expanded as $entry) {
                $outcome = $this->runSyncAttempt(
                    $entry,
                    $sheetsApi,
                    $parser,
                    $almaApi,
                    $idResolver,
                    $startRow,
                    $endRow,
                    $delayMs,
                    $logger,
                );
                if ($outcome['ok']) {
                    $ok++;
                    $processedItems += $outcome['processed'];
                    $skippedItems += $outcome['skipped'];
                } else {
                    $failed++;
                    if ($outcome['quota']) {
                        $quotaRetry[] = $entry;
                    }
                }

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }

            $quotaPasses = max(0, (int) config('services.landlord_finance.quota_retry_passes', 2));
            $quotaSleepSec = max(0, (int) config('services.landlord_finance.quota_retry_sleep_sec', 65));
            for ($pass = 1; $pass <= $quotaPasses && $quotaRetry !== []; $pass++) {
                if ($quotaSleepSec > 0) {
                    $logger->info('Landlord finance waiting before Google quota retry', [
                        'pass' => $pass,
                        'sleep_sec' => $quotaSleepSec,
                        'pending' => count($quotaRetry),
                    ]);
                    sleep($quotaSleepSec);
                }

                $stillQuota = [];
                foreach ($quotaRetry as $entry) {
                    $entry->refresh();
                    $outcome = $this->runSyncAttempt(
                        $entry,
                        $sheetsApi,
                        $parser,
                        $almaApi,
                        $idResolver,
                        $startRow,
                        $endRow,
                        $delayMs,
                        $logger,
                    );
                    if ($outcome['ok']) {
                        $ok++;
                        $failed = max(0, $failed - 1);
                        $processedItems += $outcome['processed'];
                        $skippedItems += $outcome['skipped'];
                        $logger->info('Landlord finance quota retry recovered', [
                            'id' => $entry->id,
                            'pass' => $pass,
                        ]);
                    } elseif ($outcome['quota']) {
                        $stillQuota[] = $entry;
                    }

                    if ($delayMs > 0) {
                        usleep($delayMs * 1000);
                    }
                }
                $quotaRetry = $stillQuota;
            }

            $logger->info('Landlord finance collect finished', [
                'entries' => $expanded->count(),
                'ok' => $ok,
                'failed' => $failed,
                'quota_unresolved' => count($quotaRetry),
                'processed_items' => $processedItems,
                'skipped_items' => $skippedItems,
                'dry_run' => $this->dryRun,
            ]);

            if ($failed > 0 && $ok === 0) {
                throw new RuntimeException('All landlord finance sheets failed ('.$failed.').');
            }
        } catch (Throwable $e) {
            $logger->error('Landlord finance collect failed', [
                'error' => $e->getMessage(),
            ]);
            if ($this->notifyOnFailure) {
                $collectFailureAlert->notify(
                    'landlord_finance_collect_failed',
                    '📊',
                    'Landlord finance collect упал',
                    'AE',
                    'проверь Google SA / реестр / Bitrix 148 / Alma',
                    $e,
                );
            }

            throw $e;
        }
    }

    /**
     * Run one sheet sync attempt and persist success/error.
     *
     * @return array{ok: bool, quota: bool, processed: int, skipped: int}
     */
    private function runSyncAttempt(
        LandlordFinanceSheet $entry,
        GoogleSheetsApi $sheetsApi,
        LandlordFinanceSheetParser $parser,
        AlmaApi $almaApi,
        LandlordFinanceIdResolver $idResolver,
        int $startRow,
        int $endRow,
        int $delayMs,
        LoggerInterface $logger,
    ): array {
        try {
            $result = $this->syncEntry(
                $entry,
                $sheetsApi,
                $parser,
                $almaApi,
                $idResolver,
                $startRow,
                $endRow,
                $delayMs,
                $logger,
            );
            if (! $this->dryRun) {
                $entry->forceFill([
                    'last_synced_at' => now(),
                    'last_error' => null,
                ])->save();
            }

            return [
                'ok' => true,
                'quota' => false,
                'processed' => $result['processed'],
                'skipped' => $result['skipped'],
            ];
        } catch (Throwable $e) {
            $entry->forceFill([
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();
            $logger->error('Landlord finance sheet failed', [
                'id' => $entry->id,
                'spreadsheet_id' => $entry->spreadsheet_id,
                'sheet_name' => $entry->sheet_name,
                'landlord_id' => $entry->landlord_id,
                'apart_id' => $entry->apart_id,
                'bitrix_ownership_id' => $entry->bitrix_ownership_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'quota' => $this->isGoogleQuotaError($e),
                'processed' => 0,
                'skipped' => 0,
            ];
        }
    }

    /**
     * Whether the failure is a Google Sheets/Drive rate limit.
     */
    private function isGoogleQuotaError(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, '(429)')
            || str_contains($message, 'Quota exceeded')
            || str_contains($message, 'RATE_LIMIT_EXCEEDED')
            || str_contains($message, 'RESOURCE_EXHAUSTED');
    }

    /**
     * Expand workbook template rows into concrete per-sheet registry targets.
     *
     * @return list<LandlordFinanceSheet>
     */
    private function expandEntry(
        LandlordFinanceSheet $entry,
        GoogleSheetsApi $sheetsApi,
        LandlordFinanceIdResolver $idResolver,
        LoggerInterface $logger,
    ): array {
        $sheetName = trim((string) ($entry->sheet_name ?? ''));
        if ($sheetName !== '') {
            return [$entry];
        }

        $titles = $sheetsApi->sheetTitles($entry->spreadsheet_id);
        $layout = $this->detectLayout($entry, $titles);

        if ($layout !== 'unit_tabs') {
            return [$entry];
        }

        $yearFilter = $this->resolveYearFilter();
        $targets = [];

        foreach ($titles as $title) {
            if (preg_match('/^(\d{4})\s+.+/', $title, $m) !== 1) {
                continue;
            }
            $year = (int) $m[1];
            if ($yearFilter !== null && ! in_array($year, $yearFilter, true)) {
                continue;
            }

            $child = LandlordFinanceSheet::query()->firstOrNew([
                'spreadsheet_id' => $entry->spreadsheet_id,
                'sheet_name' => $title,
            ]);

            $child->fill([
                'title' => $entry->title,
                'layout' => 'unit_tabs',
                'is_active' => true,
            ]);

            $this->ensureAlmaIds($child, $sheetsApi, $idResolver, $title, true);
            if (! $this->dryRun) {
                $child->save();
            }

            $logger->info('Unit tab binding ready', [
                'id' => $child->id,
                'spreadsheet_id' => $child->spreadsheet_id,
                'sheet_name' => $child->sheet_name,
                'landlord_id' => $child->landlord_id,
                'apart_id' => $child->apart_id,
                'bitrix_ownership_id' => $child->bitrix_ownership_id,
                'persisted' => ! $this->dryRun,
            ]);

            $targets[] = $child;
        }

        if (! $this->dryRun && $entry->landlord_id === null && $entry->apart_id === null) {
            $entry->forceFill([
                'layout' => 'unit_tabs',
                'last_synced_at' => now(),
                'last_error' => null,
            ])->save();
        }

        return $targets;
    }

    /**
     * Sync one concrete registry target.
     *
     * @return array{processed: int, skipped: int}
     */
    private function syncEntry(
        LandlordFinanceSheet $entry,
        GoogleSheetsApi $sheetsApi,
        LandlordFinanceSheetParser $parser,
        AlmaApi $almaApi,
        LandlordFinanceIdResolver $idResolver,
        int $startRow,
        int $endRow,
        int $delayMs,
        LoggerInterface $logger,
    ): array {
        $titles = $sheetsApi->sheetTitles($entry->spreadsheet_id);
        $this->ensureAlmaIds($entry, $sheetsApi, $idResolver, trim((string) ($entry->sheet_name ?? '')), false);
        if (! $this->dryRun && $entry->exists && $entry->isDirty()) {
            $entry->save();
        }

        $landlordId = trim((string) ($entry->landlord_id ?? ''));
        $apartId = trim((string) ($entry->apart_id ?? ''));
        if ($landlordId === '' || $apartId === '') {
            throw new RuntimeException(
                'Registry #'.($entry->id ?: 'new').' needs Alma landlord_id/apart_id (external_id from Alma or one-time sheet cells).'
            );
        }

        $targets = $this->resolveTabs($entry, $titles, $sheetsApi);
        if ($targets === []) {
            $logger->info('No finance tabs to sync', [
                'id' => $entry->id,
                'spreadsheet_id' => $entry->spreadsheet_id,
                'sheet_name' => $entry->sheet_name,
            ]);

            return ['processed' => 0, 'skipped' => 0];
        }

        $processed = 0;
        $skipped = 0;

        foreach ($targets as $target) {
            $sheetName = $target['sheet'];
            $year = $target['year'];
            $range = $this->quoteSheet($sheetName).'!B'.$startRow.':M'.$endRow;
            $rows = $sheetsApi->values($entry->spreadsheet_id, $range);
            $payload = $parser->buildPayload($landlordId, $apartId, $year, $rows);

            if ($payload === []) {
                $logger->info('No finance data for tab', [
                    'id' => $entry->id,
                    'sheet' => $sheetName,
                    'year' => $year,
                ]);

                continue;
            }

            $logger->info('Landlord finance payload ready', [
                'id' => $entry->id,
                'spreadsheet_id' => $entry->spreadsheet_id,
                'sheet' => $sheetName,
                'landlord_id' => $landlordId,
                'apart_id' => $apartId,
                'bitrix_ownership_id' => $entry->bitrix_ownership_id,
                'year' => $year,
                'items' => count($payload),
                'dry_run' => $this->dryRun,
            ]);

            if ($this->dryRun) {
                continue;
            }

            $response = $almaApi->pushLandlordPropertyFinance($payload);
            $processed += (int) ($response['processed_items'] ?? count($payload));
            $skipped += (int) ($response['skipped_items'] ?? 0);

            $logger->info('Landlord finance pushed', [
                'id' => $entry->id,
                'sheet' => $sheetName,
                'year' => $year,
                'processed_items' => $response['processed_items'] ?? null,
                'skipped_items' => $response['skipped_items'] ?? null,
            ]);

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        return ['processed' => $processed, 'skipped' => $skipped];
    }

    /**
     * Fill finance ids from Alma (external_id). Sheet cells are one-time bootstrap only.
     */
    private function ensureAlmaIds(
        LandlordFinanceSheet $entry,
        GoogleSheetsApi $sheetsApi,
        LandlordFinanceIdResolver $idResolver,
        string $sheetNameForCells,
        bool $allowCellBootstrap,
    ): void {
        $landlordId = trim((string) ($entry->landlord_id ?? ''));
        $apartId = trim((string) ($entry->apart_id ?? ''));

        if ($landlordId !== '' && $apartId !== '') {
            return;
        }

        if ($apartId !== '') {
            $resolved = $idResolver->fromApartExternalId($apartId);
            $entry->landlord_id = $resolved['landlord_id'];
            $entry->apart_id = $resolved['apart_id'];

            return;
        }

        if (! $allowCellBootstrap || $sheetNameForCells === '') {
            return;
        }

        $apartIdCell = (string) config('services.landlord_finance.apart_id_cell', 'E2');
        $landlordIdCell = (string) config('services.landlord_finance.landlord_id_cell', 'E3');
        $quoted = $this->quoteSheet($sheetNameForCells);
        $apartRows = $sheetsApi->values($entry->spreadsheet_id, $quoted.'!'.$apartIdCell);
        $landlordRows = $sheetsApi->values($entry->spreadsheet_id, $quoted.'!'.$landlordIdCell);

        $bootApart = trim((string) ($apartRows[0][0] ?? ''));
        $bootLandlord = trim((string) ($landlordRows[0][0] ?? ''));

        if ($bootApart !== '' && $bootLandlord !== '') {
            $resolved = $idResolver->fromExternalPair($bootLandlord, $bootApart);
            $entry->landlord_id = $resolved['landlord_id'];
            $entry->apart_id = $resolved['apart_id'];

            return;
        }

        if ($bootApart !== '') {
            $resolved = $idResolver->fromApartExternalId($bootApart);
            $entry->landlord_id = $resolved['landlord_id'];
            $entry->apart_id = $resolved['apart_id'];
        }
    }

    /**
     * @param  list<string>  $titles
     * @return list<array{sheet: string, year: int}>
     */
    private function resolveTabs(LandlordFinanceSheet $entry, array $titles, GoogleSheetsApi $sheetsApi): array
    {
        $sheetName = trim((string) ($entry->sheet_name ?? ''));
        $yearFilter = $this->resolveYearFilter();

        if ($sheetName !== '') {
            if (! in_array($sheetName, $titles, true)) {
                throw new RuntimeException('Sheet "'.$sheetName.'" not found in spreadsheet.');
            }
            $year = null;
            if (preg_match('/^(\d{4})(?:\s+|$)/', $sheetName, $m) === 1) {
                $year = (int) $m[1];
            } else {
                $year = $this->yearFromSheetBody($sheetsApi, $entry->spreadsheet_id, $sheetName);
            }
            if ($year === null) {
                throw new RuntimeException('Sheet "'.$sheetName.'" has no year in title or body.');
            }
            if ($yearFilter !== null && ! in_array($year, $yearFilter, true)) {
                return [];
            }

            return [['sheet' => $sheetName, 'year' => $year]];
        }

        $targets = [];
        $years = $yearFilter;
        if ($years === null) {
            $years = [];
            foreach ($titles as $title) {
                if (preg_match('/^\d{4}$/', $title) === 1) {
                    $years[] = (int) $title;
                }
            }
            $years = array_values(array_unique($years));
            sort($years);
        }

        foreach ($years as $year) {
            $name = (string) $year;
            if (in_array($name, $titles, true)) {
                $targets[] = ['sheet' => $name, 'year' => $year];
            }
        }

        return $targets;
    }

    /**
     * Read finance year marker from sheet body (e.g. A6 = 2026).
     */
    private function yearFromSheetBody(GoogleSheetsApi $sheetsApi, string $spreadsheetId, string $sheetName): ?int
    {
        $rows = $sheetsApi->values($spreadsheetId, $this->quoteSheet($sheetName).'!A1:A30');
        foreach ($rows as $row) {
            $raw = $row[0] ?? null;
            if (is_numeric($raw)) {
                $year = (int) $raw;
                if ($year >= 2000 && $year <= 2100) {
                    return $year;
                }
            }
            if (is_string($raw) && preg_match('/^\s*(\d{4})\s*$/', $raw, $m) === 1) {
                return (int) $m[1];
            }
        }

        $yearFilter = $this->resolveYearFilter();
        if ($yearFilter !== null && count($yearFilter) === 1) {
            return $yearFilter[0];
        }

        return null;
    }

    /**
     * @param  list<string>  $titles
     */
    private function detectLayout(LandlordFinanceSheet $entry, array $titles): string
    {
        $layout = trim((string) ($entry->layout ?? 'auto'));
        if ($layout === 'year_tabs' || $layout === 'unit_tabs') {
            return $layout;
        }

        foreach ($titles as $title) {
            if (preg_match('/^\d{4}\s+.+/', $title) === 1) {
                return 'unit_tabs';
            }
        }

        return 'year_tabs';
    }

    /**
     * Quote sheet title for A1 notation.
     */
    private function quoteSheet(string $sheetName): string
    {
        return "'".str_replace("'", "''", $sheetName)."'";
    }

    /**
     * @return list<int>|null
     */
    private function resolveYearFilter(): ?array
    {
        if ($this->years !== null && $this->years !== []) {
            return array_values(array_unique(array_map('intval', $this->years)));
        }

        $configured = trim((string) config('services.landlord_finance.years', '2026'));
        if ($configured === '') {
            return [2026];
        }

        $years = [];
        foreach (explode(',', $configured) as $part) {
            $year = (int) trim($part);
            if ($year >= 2000 && $year <= 2100) {
                $years[] = $year;
            }
        }

        return $years === [] ? [2026] : array_values(array_unique($years));
    }
}
