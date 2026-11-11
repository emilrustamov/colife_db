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

        try {
            foreach ($expanded as $entry) {
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
                    $ok++;
                    $processedItems += $result['processed'];
                    $skippedItems += $result['skipped'];
                    if (! $this->dryRun) {
                        $entry->forceFill([
                            'last_synced_at' => now(),
                            'last_error' => null,
                        ])->save();
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $entry->forceFill([
                        'last_error' => mb_substr($e->getMessage(), 0, 2000),
                    ])->save();
                    $logger->error('Landlord finance sheet failed', [
                        'id' => $entry->id,
                        'spreadsheet_id' => $entry->spreadsheet_id,
                        'sheet_name' => $entry->sheet_name,
                        'landlord_id' => $entry->landlord_id,
                        'unit_id' => $entry->unit_id,
                        'bitrix_ownership_id' => $entry->bitrix_ownership_id,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }

            $logger->info('Landlord finance collect finished', [
                'entries' => $expanded->count(),
                'ok' => $ok,
                'failed' => $failed,
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
            $collectFailureAlert->notify(
                'landlord_finance_collect_failed',
                '📊',
                'Landlord finance collect упал',
                'AE',
                'проверь Google SA / реестр / Bitrix 148 / Alma',
                $e,
            );

            throw $e;
        }
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
                'unit_id' => $child->unit_id,
                'bitrix_ownership_id' => $child->bitrix_ownership_id,
                'persisted' => ! $this->dryRun,
            ]);

            $targets[] = $child;
        }

        if (! $this->dryRun && $entry->landlord_id === null && $entry->unit_id === null) {
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
        $unitId = trim((string) ($entry->unit_id ?? ''));
        if ($landlordId === '' || $unitId === '') {
            throw new RuntimeException(
                'Registry #'.($entry->id ?: 'new').' needs Alma landlord_id/unit_id (external_id from Alma or one-time sheet cells).'
            );
        }

        $targets = $this->resolveTabs($entry, $titles);
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
            $payload = $parser->buildPayload($landlordId, $unitId, $year, $rows);

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
                'unit_id' => $unitId,
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
        $unitId = trim((string) ($entry->unit_id ?? ''));

        if ($landlordId !== '' && $unitId !== '') {
            return;
        }

        if ($unitId !== '') {
            $resolved = $idResolver->fromUnitExternalId($unitId);
            $entry->landlord_id = $resolved['landlord_id'];
            $entry->unit_id = $resolved['unit_id'];

            return;
        }

        if (! $allowCellBootstrap || $sheetNameForCells === '') {
            return;
        }

        $unitIdCell = (string) config('services.landlord_finance.unit_id_cell', 'E2');
        $landlordIdCell = (string) config('services.landlord_finance.landlord_id_cell', 'E3');
        $quoted = $this->quoteSheet($sheetNameForCells);
        $unitRows = $sheetsApi->values($entry->spreadsheet_id, $quoted.'!'.$unitIdCell);
        $landlordRows = $sheetsApi->values($entry->spreadsheet_id, $quoted.'!'.$landlordIdCell);

        $bootUnit = trim((string) ($unitRows[0][0] ?? ''));
        $bootLandlord = trim((string) ($landlordRows[0][0] ?? ''));

        if ($bootUnit !== '' && $bootLandlord !== '') {
            $resolved = $idResolver->fromExternalPair($bootLandlord, $bootUnit);
            $entry->landlord_id = $resolved['landlord_id'];
            $entry->unit_id = $resolved['unit_id'];

            return;
        }

        if ($bootUnit !== '') {
            $resolved = $idResolver->fromUnitExternalId($bootUnit);
            $entry->landlord_id = $resolved['landlord_id'];
            $entry->unit_id = $resolved['unit_id'];
        }
    }

    /**
     * @param  list<string>  $titles
     * @return list<array{sheet: string, year: int}>
     */
    private function resolveTabs(LandlordFinanceSheet $entry, array $titles): array
    {
        $sheetName = trim((string) ($entry->sheet_name ?? ''));
        $yearFilter = $this->resolveYearFilter();

        if ($sheetName !== '') {
            if (! in_array($sheetName, $titles, true)) {
                throw new RuntimeException('Sheet "'.$sheetName.'" not found in spreadsheet.');
            }
            if (preg_match('/^(\d{4})(?:\s+|$)/', $sheetName, $m) !== 1) {
                throw new RuntimeException('Sheet "'.$sheetName.'" must start with year YYYY.');
            }
            $year = (int) $m[1];
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

        $configured = trim((string) config('services.landlord_finance.years', ''));
        if ($configured === '') {
            return null;
        }

        $years = [];
        foreach (explode(',', $configured) as $part) {
            $year = (int) trim($part);
            if ($year >= 2000 && $year <= 2100) {
                $years[] = $year;
            }
        }

        return array_values(array_unique($years));
    }
}
