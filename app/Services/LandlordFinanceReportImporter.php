<?php

namespace App\Services;

use App\Jobs\CollectLandlordFinance;
use App\Models\LandlordFinanceSheet;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class LandlordFinanceReportImporter
{
    /**
     * @var list<array{id: int|string, ext: string, name: string}>|null
     */
    private ?array $unitsCache = null;

    public function __construct(
        private readonly GoogleSheetsApi $sheetsApi,
        private readonly AlmaApi $almaApi,
        private readonly BitrixRest $bitrixRest,
    ) {}

    /**
     * Import report links from Reports_2026 month tabs (Oct → Jan) and push year data.
     *
     * @param  list<string>  $months
     * @return array{
     *     links: int,
     *     unique: int,
     *     ok: int,
     *     failed: int,
     *     errors: list<array<string, mixed>>
     * }
     */
    public function importFromIndex(
        string $indexSpreadsheetId,
        array $months,
        int $year = 2026,
        bool $dryRun = false,
    ): array {
        $logger = Log::channel('landlord_finance');
        $links = $this->collectLinks($indexSpreadsheetId, $months);
        $unique = $this->dedupeLinks($links);

        $ok = 0;
        $failed = 0;
        $errors = [];

        $logger->info('Landlord finance index import started', [
            'links' => count($links),
            'unique' => count($unique),
            'months' => $months,
            'year' => $year,
            'dry_run' => $dryRun,
        ]);

        foreach ($unique as $i => $link) {
            try {
                $result = $this->importOne($link, $year, $dryRun);
                $ok++;
                $logger->info('Landlord finance index import ok', [
                    'n' => $i + 1,
                    'total' => count($unique),
                    'name' => $link['name'],
                    'registry_id' => $result['registry_id'],
                    'processed' => $result['processed'] ?? null,
                    'skipped' => $result['skipped'] ?? null,
                ]);
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
                $logger->error('Landlord finance index import failed', $error);
            }

            $delayMs = max(800, (int) config('services.landlord_finance.request_delay_ms', 200));
            usleep($delayMs * 1000);
        }

        $summary = [
            'links' => count($links),
            'unique' => count($unique),
            'ok' => $ok,
            'failed' => $failed,
            'errors' => $errors,
        ];

        $path = storage_path('app/google/landlord_finance_import_errors.json');
        file_put_contents($path, json_encode($summary, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");

        $logger->info('Landlord finance index import finished', [
            'ok' => $ok,
            'failed' => $failed,
            'errors_file' => $path,
        ]);

        return $summary;
    }

    /**
     * @param  list<string>  $months
     * @return list<array{name: string, spreadsheet_id: string, gid: string|null, url: string, month: string}>
     */
    public function collectLinks(string $indexSpreadsheetId, array $months): array
    {
        $token = $this->sheetsApi->accessTokenValue();
        $links = [];

        foreach ($months as $month) {
            $query = http_build_query([
                'includeGridData' => 'true',
                'fields' => 'sheets.data.rowData.values(formattedValue,hyperlink,userEnteredValue)',
            ]);
            $query .= '&ranges='.rawurlencode("'{$month}'!A1:B");

            $meta = null;
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $meta = Http::withToken($token)
                    ->acceptJson()
                    ->timeout((int) config('services.landlord_finance.timeout', 60))
                    ->get('https://sheets.googleapis.com/v4/spreadsheets/'.$indexSpreadsheetId.'?'.$query);

                if ($meta->status() !== 429) {
                    break;
                }
                usleep((int) (min(30, 2 ** $attempt) * 1_000_000));
            }

            if ($meta === null || $meta->failed()) {
                throw new RuntimeException(
                    'Index sheet '.$month.' failed ('.($meta?->status() ?? 0).'): '.($meta?->body() ?? '')
                );
            }

            $rows = $meta->json('sheets.0.data.0.rowData') ?? [];
            if (! is_array($rows)) {
                continue;
            }

            for ($i = 1; $i < count($rows); $i++) {
                $cell = $rows[$i]['values'][0] ?? null;
                if (! is_array($cell)) {
                    continue;
                }
                $name = trim((string) ($cell['formattedValue'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $url = trim((string) ($cell['hyperlink'] ?? ''));
                if ($url === '' && isset($cell['userEnteredValue']['formulaValue'])) {
                    if (preg_match('/HYPERLINK\("([^"]+)"/i', (string) $cell['userEnteredValue']['formulaValue'], $m) === 1) {
                        $url = $m[1];
                    }
                }
                if ($url === '') {
                    continue;
                }

                if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $url, $sm) !== 1) {
                    continue;
                }
                $gid = null;
                if (preg_match('/[?#&]gid=(\d+)/', $url, $gm) === 1) {
                    $gid = $gm[1];
                }

                $links[] = [
                    'name' => $name,
                    'spreadsheet_id' => $sm[1],
                    'gid' => $gid,
                    'url' => $url,
                    'month' => $month,
                ];
            }
        }

        return $links;
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

    /**
     * @param  array{name: string, spreadsheet_id: string, gid: string|null, url: string}  $link
     * @return array{registry_id: int, processed?: int, skipped?: int}
     */
    private function importOne(array $link, int $year, bool $dryRun): array
    {
        $spreadsheetId = $link['spreadsheet_id'];
        $meta = $this->sheetsApi->spreadsheetMeta($spreadsheetId);
        $bookTitle = (string) ($meta['title'] ?? '');
        $sheets = $meta['sheets'];

        $targetTitle = null;
        if (! empty($link['gid'])) {
            foreach ($sheets as $sheet) {
                if ((string) ($sheet['id'] ?? '') === (string) $link['gid']) {
                    $targetTitle = (string) $sheet['title'];
                    break;
                }
            }
        }
        if ($targetTitle === null) {
            foreach ($sheets as $sheet) {
                if ((string) ($sheet['title'] ?? '') === (string) $year) {
                    $targetTitle = (string) $sheet['title'];
                    break;
                }
            }
        }
        if ($targetTitle === null && $sheets !== []) {
            $targetTitle = (string) $sheets[0]['title'];
        }
        if ($targetTitle === null || $targetTitle === '') {
            throw new RuntimeException('Target worksheet not found.');
        }

        if (preg_match('/^\d{4}\s+.+/', $targetTitle) === 1) {
            $layout = 'unit_tabs';
        } elseif (preg_match('/^\d{4}$/', $targetTitle) === 1) {
            $layout = 'year_tabs';
        } else {
            $layout = 'apt_tabs';
        }
        $sheetName = $targetTitle;

        $existing = LandlordFinanceSheet::query()
            ->where('spreadsheet_id', $spreadsheetId)
            ->where('sheet_name', $sheetName)
            ->whereNotNull('last_synced_at')
            ->whereNull('last_error')
            ->orderByDesc('id')
            ->first();
        if ($existing !== null && ! $dryRun) {
            return [
                'registry_id' => (int) $existing->id,
                'processed' => null,
                'skipped' => null,
            ];
        }

        $quoted = "'".str_replace("'", "''", $targetTitle)."'";
        $header = $this->sheetsApi->values($spreadsheetId, $quoted.'!A1:E4');
        $aptName = trim((string) ($header[0][2] ?? $header[0][1] ?? ''));
        $ownerName = trim((string) ($header[1][2] ?? $header[1][1] ?? ''));

        $landlordId = '';
        $apartId = '';
        $ownershipId = null;

        if ($layout === 'unit_tabs') {
            $apartIdCell = (string) config('services.landlord_finance.apart_id_cell', 'E2');
            $landlordIdCell = (string) config('services.landlord_finance.landlord_id_cell', 'E3');
            $apartRows = $this->sheetsApi->values($spreadsheetId, $quoted.'!'.$apartIdCell);
            $landlordRows = $this->sheetsApi->values($spreadsheetId, $quoted.'!'.$landlordIdCell);
            $apartId = trim((string) ($apartRows[0][0] ?? ''));
            $landlordId = trim((string) ($landlordRows[0][0] ?? ''));
        }

        if ($apartId === '' || $landlordId === '') {
            $aptNo = $this->parseAptNumber($link['name'].' '.$bookTitle.' '.$aptName);
            $unit = $this->findAlmaUnit($aptNo, $aptName, $bookTitle, $link['name']);
            $apartId = (string) $unit['ext'];

            $ownership = $this->bitrixOwnershipForUnit($apartId);
            $landlordId = trim((string) ($ownership['contactId'] ?? ''));
            $ownershipId = ((int) ($ownership['id'] ?? 0)) ?: null;
            if ($landlordId === '') {
                throw new RuntimeException('Bitrix SPA 148 contactId empty for apartment '.$apartId.'.');
            }
        }

        $client = $this->almaApi->clientByExternalId($landlordId);
        if ($client === null || empty($client['id'])) {
            throw new RuntimeException(
                'Alma client missing for landlord external_id '.$landlordId
                .($ownerName !== '' ? ' ('.$ownerName.')' : '')
                .'. Often no email in Bitrix.'
            );
        }

        $unitCheck = $this->almaApi->unitByExternalId($apartId);
        if ($unitCheck === null || empty($unitCheck['id'])) {
            throw new RuntimeException('Alma unit missing for external_id '.$apartId.'.');
        }

        $entry = LandlordFinanceSheet::query()->updateOrCreate(
            [
                'spreadsheet_id' => $spreadsheetId,
                'sheet_name' => $sheetName,
            ],
            [
                'title' => $aptName !== '' ? $aptName : ($link['name'] !== '' ? $link['name'] : $bookTitle),
                'layout' => $layout,
                'landlord_id' => $landlordId,
                'apart_id' => $apartId,
                'bitrix_ownership_id' => $ownershipId,
                'is_active' => true,
                'last_error' => null,
            ]
        );

        if ($dryRun) {
            return ['registry_id' => (int) $entry->id];
        }

        CollectLandlordFinance::dispatchSync((int) $entry->id, [$year], false, false);

        $entry->refresh();
        if ($entry->last_error) {
            throw new RuntimeException((string) $entry->last_error);
        }

        return [
            'registry_id' => (int) $entry->id,
            'processed' => null,
            'skipped' => null,
        ];
    }

    /**
     * @return array{id: int|string, ext: string, name: string}
     */
    private function findAlmaUnit(?string $aptNo, string $aptName, string $bookTitle, string $linkName): array
    {
        $units = $this->almaUnits();

        if ($aptNo !== null && $aptNo !== '') {
            $exact = [];
            foreach ($units as $unit) {
                if (preg_match('/Ap\.\s*0*'.preg_quote($aptNo, '/').'\b/i', $unit['name']) === 1) {
                    $exact[] = $unit;
                }
            }
            if (count($exact) === 1) {
                return $exact[0];
            }
            if (count($exact) > 1) {
                $picked = $this->pickUnitByText($exact, $aptName.' '.$bookTitle.' '.$linkName);
                if ($picked !== null) {
                    return $picked;
                }

                return $exact[0];
            }
        }

        $picked = $this->pickUnitByText($units, $aptName !== '' ? $aptName : $linkName);
        if ($picked !== null) {
            return $picked;
        }

        throw new RuntimeException(
            'Alma unit not found for aptNo='.($aptNo ?? '∅').' name='.($aptName !== '' ? $aptName : $linkName)
        );
    }

    /**
     * @param  list<array{id: int|string, ext: string, name: string}>  $units
     * @return array{id: int|string, ext: string, name: string}|null
     */
    private function pickUnitByText(array $units, string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $normalized = mb_strtolower($text);
        $best = null;
        $bestScore = 0;
        foreach ($units as $unit) {
            $name = mb_strtolower($unit['name']);
            $score = 0;
            if ($normalized !== '' && str_contains($name, $normalized)) {
                $score += 100;
            }
            foreach (preg_split('/[\s,\/\-]+/u', $normalized) ?: [] as $token) {
                $token = trim($token);
                if (mb_strlen($token) < 3) {
                    continue;
                }
                if (str_contains($name, $token)) {
                    $score += 10;
                }
            }
            if (preg_match('/\b(\d{2,4})\b/', $text, $m) === 1 && str_contains($name, $m[1])) {
                $score += 20;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $unit;
            }
        }

        return $bestScore >= 30 ? $best : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function bitrixOwnershipForUnit(string $unitExternalId): array
    {
        $domain = rtrim(trim((string) config('services.bitrix.portal_domain', '')), '/');
        $entityTypeId = (int) config('services.landlord_finance.bitrix_ownership_entity_type_id', 148);

        $response = $this->bitrixRest->postJson('crm.item.list.json', [
            'entityTypeId' => $entityTypeId,
            'filter' => [
                'ufCrm10_1693823301' => $unitExternalId,
            ],
            'select' => [
                'id',
                'title',
                'stageId',
                'contactId',
                'parentId144',
                'ufCrm10_1693823301',
            ],
            'order' => ['id' => 'DESC'],
        ], $domain);

        $items = $response['result']['items'] ?? [];
        if (! is_array($items) || $items === []) {
            throw new RuntimeException('Bitrix SPA 148 not found for apartment '.$unitExternalId.'.');
        }

        foreach ($items as $item) {
            $stage = strtoupper((string) ($item['stageId'] ?? ''));
            if (str_contains($stage, ':NEW') || str_contains($stage, 'PROCESS') || str_contains($stage, 'PREPARATION')) {
                return $item;
            }
        }
        foreach ($items as $item) {
            $stage = strtoupper((string) ($item['stageId'] ?? ''));
            if (! str_contains($stage, 'FAIL')) {
                return $item;
            }
        }

        return $items[0];
    }

    private function parseAptNumber(string $text): ?string
    {
        if (preg_match('/(?:Ap\.?\s*)?(\d{2,4})\.\s+/u', $text, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^(\d{2,4})[\.\s]/u', trim($text), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return list<array{id: int|string, ext: string, name: string}>
     */
    private function almaUnits(): array
    {
        if ($this->unitsCache !== null) {
            return $this->unitsCache;
        }

        $units = [];
        foreach ($this->almaApi->listUnits() as $unit) {
            $ext = trim((string) ($unit['external_id'] ?? ''));
            $name = trim((string) ($unit['name'] ?? ''));
            $id = $unit['id'] ?? null;
            if ($ext === '' || $name === '' || $id === null) {
                continue;
            }
            $units[] = ['id' => $id, 'ext' => $ext, 'name' => $name];
        }
        $this->unitsCache = $units;

        return $units;
    }

}
