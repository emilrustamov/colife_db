<?php

namespace App\Services;

use App\Models\LandlordFinanceSheet;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class LandlordFinanceBitrixReconcile
{
    public function __construct(
        private readonly BitrixRest $bitrixRest,
        private readonly TelegramAlert $telegramAlert,
        private readonly TelegramAlertText $telegramText,
        private readonly BitrixOAuth $oauth,
    ) {}

    /**
     * Compare registry Alma external_ids with Bitrix SPA 148 owner/apartment.
     *
     * @param  list<int>|null  $registryIds
     * @return array{checked: int, matched: int, mismatched: int, missing_bitrix: int, issues: list<array<string, mixed>>}
     */
    public function reconcile(?array $registryIds = null, bool $notify = true): array
    {
        $logger = Log::channel('landlord_finance');
        $query = LandlordFinanceSheet::query()
            ->active()
            ->whereNotNull('landlord_id')
            ->whereNotNull('apart_id')
            ->where('landlord_id', '!=', '')
            ->where('apart_id', '!=', '')
            ->orderBy('id');

        if ($registryIds !== null && $registryIds !== []) {
            $query->whereIn('id', $registryIds);
        }

        $checked = 0;
        $matched = 0;
        $mismatched = 0;
        $missingBitrix = 0;
        $issues = [];

        foreach ($query->cursor() as $entry) {
            $checked++;
            try {
                $result = $this->checkEntry($entry);
            } catch (Throwable $e) {
                $mismatched++;
                $issue = [
                    'registry_id' => $entry->id,
                    'spreadsheet_id' => $entry->spreadsheet_id,
                    'sheet_name' => $entry->sheet_name,
                    'alma_landlord_external_id' => (string) $entry->landlord_id,
                    'alma_apart_external_id' => (string) $entry->apart_id,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
                $issues[] = $issue;
                $logger->warning('Landlord finance Bitrix reconcile error', $issue);

                continue;
            }

            if ($result['status'] === 'matched') {
                $matched++;

                continue;
            }

            if ($result['status'] === 'missing_bitrix') {
                $missingBitrix++;
            } else {
                $mismatched++;
            }

            $issues[] = $result;
            $logger->warning('Landlord finance Bitrix reconcile mismatch', $result);
        }

        $summary = [
            'checked' => $checked,
            'matched' => $matched,
            'mismatched' => $mismatched,
            'missing_bitrix' => $missingBitrix,
            'issues' => $issues,
        ];

        $logger->info('Landlord finance Bitrix reconcile finished', [
            'checked' => $checked,
            'matched' => $matched,
            'mismatched' => $mismatched,
            'missing_bitrix' => $missingBitrix,
            'issues' => count($issues),
        ]);

        if ($notify && $issues !== []) {
            $this->notifyIssues($issues);
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkEntry(LandlordFinanceSheet $entry): array
    {
        $almaLandlord = trim((string) $entry->landlord_id);
        $almaUnit = trim((string) $entry->apart_id);
        $base = [
            'registry_id' => $entry->id,
            'spreadsheet_id' => $entry->spreadsheet_id,
            'sheet_name' => $entry->sheet_name,
            'title' => $entry->title,
            'alma_landlord_external_id' => $almaLandlord,
            'alma_apart_external_id' => $almaUnit,
        ];

        $ownerships = $this->bitrixOwnershipsForApartment($almaUnit);
        if ($ownerships === []) {
            return $base + [
                'status' => 'missing_bitrix',
                'message' => 'Bitrix SPA 148 not found for apartment '.$almaUnit,
            ];
        }

        $ownership = $this->pickCurrentOwnership($ownerships, (int) ($entry->bitrix_ownership_id ?? 0));
        $bitrixContactId = trim((string) ($ownership['contactId'] ?? ''));
        $bitrixApartmentId = $this->firstId(
            $ownership['parentId144'] ?? null,
            $ownership['ufCrm10_1693823301'] ?? null,
        );
        $bitrixOwnershipId = (int) ($ownership['id'] ?? 0);

        $ownerMatch = $bitrixContactId !== '' && $bitrixContactId === $almaLandlord;
        $apartmentMatch = $bitrixApartmentId !== '' && $bitrixApartmentId === $almaUnit;

        if ($ownerMatch && $apartmentMatch) {
            if ((int) ($entry->bitrix_ownership_id ?? 0) !== $bitrixOwnershipId && $bitrixOwnershipId > 0) {
                $entry->forceFill(['bitrix_ownership_id' => $bitrixOwnershipId])->save();
            }

            return $base + [
                'status' => 'matched',
                'bitrix_ownership_id' => $bitrixOwnershipId,
                'bitrix_contact_id' => $bitrixContactId,
                'bitrix_apartment_id' => $bitrixApartmentId,
            ];
        }

        $parts = [];
        if (! $ownerMatch) {
            $parts[] = 'owner external_id Alma='.$almaLandlord.' Bitrix contactId='.($bitrixContactId !== '' ? $bitrixContactId : '∅');
        }
        if (! $apartmentMatch) {
            $parts[] = 'apartment external_id Alma='.$almaUnit.' Bitrix='.($bitrixApartmentId !== '' ? $bitrixApartmentId : '∅');
        }

        return $base + [
            'status' => 'mismatched',
            'bitrix_ownership_id' => $bitrixOwnershipId,
            'bitrix_contact_id' => $bitrixContactId,
            'bitrix_apartment_id' => $bitrixApartmentId,
            'bitrix_title' => (string) ($ownership['title'] ?? ''),
            'bitrix_stage' => (string) ($ownership['stageId'] ?? ''),
            'message' => implode('; ', $parts),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bitrixOwnershipsForApartment(string $apartmentBitrixId): array
    {
        $domain = rtrim(trim((string) config('services.bitrix.portal_domain', '')), '/');
        $entityTypeId = (int) config('services.landlord_finance.bitrix_ownership_entity_type_id', 148);

        $response = $this->bitrixRest->postJson('crm.item.list.json', [
            'entityTypeId' => $entityTypeId,
            'filter' => [
                'ufCrm10_1693823301' => $apartmentBitrixId,
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

        $items = $response['result']['items'] ?? null;
        if (! is_array($items)) {
            $items = $response['result'] ?? [];
        }
        if (! is_array($items)) {
            throw new RuntimeException('Bitrix ownership list response is invalid.');
        }

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * @param  list<array<string, mixed>>  $ownerships
     * @return array<string, mixed>
     */
    private function pickCurrentOwnership(array $ownerships, int $preferredId): array
    {
        if ($preferredId > 0) {
            foreach ($ownerships as $item) {
                if ((int) ($item['id'] ?? 0) === $preferredId) {
                    return $item;
                }
            }
        }

        foreach ($ownerships as $item) {
            $stage = strtoupper((string) ($item['stageId'] ?? ''));
            if (str_contains($stage, ':NEW') || str_contains($stage, 'PREPARATION') || str_contains($stage, 'PROCESS')) {
                return $item;
            }
        }

        return $ownerships[0];
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    private function notifyIssues(array $issues): void
    {
        $lines = [];
        foreach (array_slice($issues, 0, 15) as $issue) {
            $lines[] = '#'.($issue['registry_id'] ?? '?')
                .' unit='.($issue['alma_apart_external_id'] ?? '')
                .' '.($issue['message'] ?? ($issue['status'] ?? ''));
        }
        if (count($issues) > 15) {
            $lines[] = '… ещё '.(count($issues) - 15);
        }

        $domain = null;
        $portals = $this->oauth->configuredPortals();
        if (isset($portals['AE'])) {
            $domain = $portals['AE'];
        }

        $message = $this->telegramText->message(
            '📊',
            'Landlord finance: Alma ↔ Bitrix 148 расхождения',
            $domain,
            'несовпадений: '.count($issues)."\n".implode("\n", $lines),
        );

        $this->telegramAlert->notifyOnce(
            'landlord_finance_bitrix_reconcile_'.md5(json_encode($issues)),
            $message,
        );
    }

    /**
     * @param  mixed  ...$candidates
     */
    private function firstId(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $candidate = $candidate[0] ?? null;
            }
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '' && $value !== '0') {
                return $value;
            }
        }

        return '';
    }
}
