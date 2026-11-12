<?php

namespace App\Services;

use App\Models\LandlordFinanceSheet;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class LandlordFinanceRegistrySync
{
    public function __construct(
        private readonly GoogleSheetsApi $sheetsApi,
        private readonly LandlordFinanceReportImporter $importer,
        private readonly AlmaApi $almaApi,
    ) {}

    /**
     * Fill landlord_finance_sheets from Reports_2026 links. Never pushes to Alma.
     *
     * @param  list<string>  $months
     * @return array{
     *     links: int,
     *     unique: int,
     *     ok: int,
     *     skipped_summary: int,
     *     failed: int,
     *     external: int,
     *     errors: list<array<string, mixed>>
     * }
     */
    public function syncFromIndex(
        string $indexSpreadsheetId,
        array $months,
        int $year = 2026,
    ): array {
        $logger = Log::channel('landlord_finance');
        $links = $this->importer->collectLinks($indexSpreadsheetId, $months);
        $unique = $this->dedupeLinks($links);

        $ok = 0;
        $failed = 0;
        $skippedSummary = 0;
        $external = 0;
        $errors = [];

        $logger->info('Landlord finance registry sync started (DB only, no Alma push)', [
            'links' => count($links),
            'unique' => count($unique),
            'months' => $months,
            'year' => $year,
        ]);

        foreach ($unique as $i => $link) {
            try {
                $result = $this->syncOne($link, $year);
                if (($result['skipped_summary'] ?? false) === true) {
                    $skippedSummary++;
                    $logger->info('Landlord finance registry skipped summary tab', [
                        'n' => $i + 1,
                        'total' => count($unique),
                        'name' => $link['name'],
                        'sheet' => $result['sheet_name'] ?? null,
                    ]);
                } else {
                    $ok++;
                    if (($result['is_external'] ?? false) === true) {
                        $external++;
                    }
                    $logger->info('Landlord finance registry ok', [
                        'n' => $i + 1,
                        'total' => count($unique),
                        'name' => $link['name'],
                        'registry_id' => $result['registry_id'],
                        'id_source' => $result['id_source'] ?? null,
                        'is_external' => $result['is_external'] ?? false,
                    ]);
                }
            } catch (Throwable $e) {
                $failed++;
                $error = [
                    'name' => $link['name'],
                    'url' => $link['url'],
                    'spreadsheet_id' => $link['spreadsheet_id'],
                    'gid' => $link['gid'],
                    'error' => $e->getMessage(),
                ];
                $errors[] = $error;
                $logger->error('Landlord finance registry failed', $error);
            }

            $delayMs = max(400, (int) config('services.landlord_finance.request_delay_ms', 200));
            usleep($delayMs * 1000);
        }

        $summary = [
            'links' => count($links),
            'unique' => count($unique),
            'ok' => $ok,
            'skipped_summary' => $skippedSummary,
            'failed' => $failed,
            'external' => $external,
            'errors' => $errors,
            'note' => 'DB registry only — Alma was not called',
        ];

        $path = storage_path('app/google/landlord_finance_registry_sync.json');
        file_put_contents(
            $path,
            json_encode($summary, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n"
        );

        $logger->info('Landlord finance registry sync finished (DB only)', [
            'ok' => $ok,
            'skipped_summary' => $skippedSummary,
            'failed' => $failed,
            'external' => $external,
            'report' => $path,
        ]);

        return $summary;
    }

    /**
     * @param  array{name: string, spreadsheet_id: string, gid: string|null, url: string}  $link
     * @return array{
     *     registry_id?: int,
     *     skipped_summary?: bool,
     *     sheet_name?: string,
     *     id_source?: string,
     *     is_external?: bool
     * }
     */
    private function syncOne(array $link, int $year): array
    {
        $spreadsheetId = $link['spreadsheet_id'];
        $meta = $this->sheetsApi->spreadsheetMeta($spreadsheetId);
        $bookTitle = (string) ($meta['title'] ?? '');
        $sheets = $meta['sheets'];

        $targetTitle = $this->resolveTargetTitle($sheets, $link['gid'] ?? null, $year);
        if ($targetTitle === null || $targetTitle === '') {
            throw new RuntimeException('Target worksheet not found for year '.$year.'.');
        }

        if ($this->isSummaryTab($targetTitle)) {
            return [
                'skipped_summary' => true,
                'sheet_name' => $targetTitle,
            ];
        }

        if (preg_match('/^\d{4}\s+.+/', $targetTitle) === 1) {
            $layout = 'unit_tabs';
        } elseif (preg_match('/^\d{4}$/', $targetTitle) === 1) {
            $layout = 'year_tabs';
        } else {
            $layout = 'apt_tabs';
        }

        $quoted = "'".str_replace("'", "''", $targetTitle)."'";
        $header = $this->sheetsApi->values($spreadsheetId, $quoted.'!A1:C2');
        $aptName = trim((string) ($header[0][2] ?? $header[0][1] ?? ''));

        $apartId = trim((string) (($this->sheetsApi->values($spreadsheetId, $quoted.'!E2')[0][0] ?? '')));
        $landlordId = trim((string) (($this->sheetsApi->values($spreadsheetId, $quoted.'!E3')[0][0] ?? '')));
        $landlordId2 = trim((string) (($this->sheetsApi->values($spreadsheetId, $quoted.'!E4')[0][0] ?? '')));
        $idSource = '';

        if ($this->looksLikeId($apartId) && $this->looksLikeId($landlordId)) {
            $idSource = 'cells';
            if (! $this->looksLikeId($landlordId2)) {
                $landlordId2 = '';
            }
        } else {
            $apartId = '';
            $landlordId = '';
            $landlordId2 = '';
        }

        if ($idSource === '') {
            $fromScript = $this->idsFromBoundScript($spreadsheetId);
            if ($this->looksLikeId($fromScript['apart_id']) && $this->looksLikeId($fromScript['landlord_id'])) {
                $apartId = $fromScript['apart_id'];
                $landlordId = $fromScript['landlord_id'];
                $landlordId2 = $fromScript['landlord_id_2'];
                $idSource = 'script';
            }
        }

        if ($idSource === '') {
            throw new RuntimeException(
                'No unit/landlord id in E2/E3 (or Apps Script setConfig) for "'.$targetTitle.'".'
            );
        }

        $almaError = $this->validateIdsInAlma($landlordId, $apartId, $landlordId2);

        $isExternal = false;
        $driveError = null;
        try {
            $drive = $this->sheetsApi->driveFileMeta($spreadsheetId);
            $isExternal = $this->detectExternal($drive);
        } catch (Throwable $e) {
            $driveError = $e->getMessage();
        }

        $lastError = $almaError;
        if ($lastError === null && $driveError !== null) {
            $lastError = 'drive meta: '.$driveError;
        }

        $entry = LandlordFinanceSheet::query()->updateOrCreate(
            [
                'spreadsheet_id' => $spreadsheetId,
                'sheet_name' => $targetTitle,
            ],
            [
                'title' => $aptName !== '' ? $aptName : ($link['name'] !== '' ? $link['name'] : $bookTitle),
                'layout' => $layout,
                'landlord_id' => $landlordId,
                'landlord_id_2' => $landlordId2 !== '' ? $landlordId2 : null,
                'apart_id' => $apartId,
                'source_url' => $link['url'],
                'is_active' => true,
                'is_external' => $isExternal,
                'id_source' => $idSource,
                'last_error' => $lastError !== null ? mb_substr($lastError, 0, 2000) : null,
            ]
        );

        return [
            'registry_id' => (int) $entry->id,
            'id_source' => $idSource,
            'is_external' => $isExternal,
        ];
    }

    /**
     * Check landlord/apart external ids exist in Alma. Returns error text or null.
     */
    public function validateIdsInAlma(string $landlordId, string $apartId, string $landlordId2 = ''): ?string
    {
        $errors = [];

        $client = $this->almaApi->clientByExternalId($landlordId);
        if ($client === null || empty($client['id'])) {
            $errors[] = 'Alma client missing for landlord_id '.$landlordId;
        }

        $unit = $this->almaApi->unitByExternalId($apartId);
        if ($unit === null || empty($unit['id'])) {
            $errors[] = 'Alma unit missing for apart_id '.$apartId;
        }

        if ($landlordId2 !== '') {
            $client2 = $this->almaApi->clientByExternalId($landlordId2);
            if ($client2 === null || empty($client2['id'])) {
                $errors[] = 'Alma client missing for landlord_id_2 '.$landlordId2;
            }
        }

        return $errors === [] ? null : implode('; ', $errors);
    }

    /**
     * Re-check all active registry ids against Alma (no finance push).
     *
     * @return array{checked: int, ok: int, failed: int, errors: list<array<string, mixed>>}
     */
    public function verifyRegistryAgainstAlma(): array
    {
        $logger = Log::channel('landlord_finance');
        $rows = LandlordFinanceSheet::query()
            ->active()
            ->whereNotNull('landlord_id')
            ->where('landlord_id', '!=', '')
            ->whereNotNull('apart_id')
            ->where('apart_id', '!=', '')
            ->orderBy('id')
            ->get();

        $ok = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $row) {
            $almaError = $this->validateIdsInAlma(
                trim((string) $row->landlord_id),
                trim((string) $row->apart_id),
                trim((string) ($row->landlord_id_2 ?? '')),
            );

            if ($almaError === null) {
                $ok++;
                if (is_string($row->last_error) && str_starts_with($row->last_error, 'Alma ')) {
                    $row->forceFill(['last_error' => null])->save();
                }
                continue;
            }

            $failed++;
            $row->forceFill(['last_error' => mb_substr($almaError, 0, 2000)])->save();
            $errors[] = [
                'id' => $row->id,
                'title' => $row->title,
                'landlord_id' => $row->landlord_id,
                'landlord_id_2' => $row->landlord_id_2,
                'apart_id' => $row->apart_id,
                'id_source' => $row->id_source,
                'error' => $almaError,
            ];
            $logger->warning('Landlord finance Alma id check failed', end($errors));
        }

        $summary = [
            'checked' => $rows->count(),
            'ok' => $ok,
            'failed' => $failed,
            'errors' => $errors,
            'note' => 'Alma lookup only — finance was not pushed',
        ];

        file_put_contents(
            storage_path('app/google/landlord_finance_alma_id_check.json'),
            json_encode($summary, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n"
        );

        $logger->info('Landlord finance Alma id check finished', [
            'checked' => $summary['checked'],
            'ok' => $ok,
            'failed' => $failed,
        ]);

        return $summary;
    }

    /**
     * @param  list<array{id: int|string, title: string}>  $sheets
     */
    private function resolveTargetTitle(array $sheets, ?string $gid, int $year): ?string
    {
        $byGid = null;
        if ($gid !== null && $gid !== '') {
            foreach ($sheets as $sheet) {
                if ((string) ($sheet['id'] ?? '') === (string) $gid) {
                    $byGid = (string) $sheet['title'];
                    break;
                }
            }
        }

        if ($byGid !== null && ! $this->isSummaryTab($byGid)) {
            if (preg_match('/^(\d{4})(\s+.+)?$/u', $byGid, $m) === 1) {
                $tabYear = (int) $m[1];
                $suffix = $m[2] ?? '';
                if ($tabYear !== $year) {
                    $wanted = (string) $year.$suffix;
                    foreach ($sheets as $sheet) {
                        if ((string) ($sheet['title'] ?? '') === $wanted) {
                            return $wanted;
                        }
                    }
                    foreach ($sheets as $sheet) {
                        if ((string) ($sheet['title'] ?? '') === (string) $year) {
                            return (string) $sheet['title'];
                        }
                    }
                    throw new RuntimeException(
                        'Link points to '.$byGid.'; tab '.$year.$suffix.' not found.'
                    );
                }
            }

            return $byGid;
        }

        foreach ($sheets as $sheet) {
            $title = (string) ($sheet['title'] ?? '');
            if ($title === (string) $year) {
                return $title;
            }
        }

        foreach ($sheets as $sheet) {
            $title = (string) ($sheet['title'] ?? '');
            if (preg_match('/^'.preg_quote((string) $year, '/').'\s+.+/', $title) === 1) {
                return $title;
            }
        }

        if ($byGid !== null && $this->isSummaryTab($byGid)) {
            return $byGid;
        }

        return $byGid;
    }

    /**
     * @return array{landlord_id: string, apart_id: string, landlord_id_2: string}
     */
    private function idsFromBoundScript(string $spreadsheetId): array
    {
        $empty = ['landlord_id' => '', 'apart_id' => '', 'landlord_id_2' => ''];
        try {
            $scriptId = $this->sheetsApi->boundScriptId($spreadsheetId);
            if ($scriptId === null) {
                return $empty;
            }
            $files = $this->sheetsApi->scriptProjectFiles($scriptId);

            return $this->sheetsApi->parseFinanceIdsFromScriptFiles($files);
        } catch (Throwable) {
            return $empty;
        }
    }

    /**
     * @param  array{
     *     can_edit: bool|null,
     *     owners: list<string>,
     *     drive_id: string|null
     * }  $drive
     */
    private function detectExternal(array $drive): bool
    {
        if ($drive['can_edit'] === false) {
            return true;
        }

        $owners = $drive['owners'];
        if ($owners === []) {
            return $drive['drive_id'] === null;
        }

        foreach ($owners as $email) {
            if (str_ends_with(mb_strtolower($email), '@colife.ae')) {
                return false;
            }
        }

        return true;
    }

    private function isSummaryTab(string $title): bool
    {
        $normalized = mb_strtolower(trim($title));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized) ?? $normalized;

        if (str_starts_with($normalized, 'прибыль') || str_starts_with($normalized, 'pribyl')) {
            return true;
        }
        if ($normalized === 'аренда' || str_starts_with($normalized, 'аренда ')) {
            return true;
        }
        if ($normalized === 'all apartments' || str_starts_with($normalized, 'all apartments')) {
            return true;
        }

        return false;
    }

    private function looksLikeId(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && preg_match('/^\d{1,12}$/', $value) === 1;
    }

    /**
     * @param  list<array{name: string, spreadsheet_id: string, gid: string|null, url: string, month?: string}>  $links
     * @return list<array{name: string, spreadsheet_id: string, gid: string|null, url: string, month?: string}>
     */
    private function dedupeLinks(array $links): array
    {
        $map = [];
        foreach ($links as $link) {
            $key = $link['spreadsheet_id'].'|'.($link['gid'] ?? '');
            $map[$key] = $link;
        }

        return array_values($map);
    }
}
