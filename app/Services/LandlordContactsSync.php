<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LandlordContactsSync
{
    public const EXCLUDED_STAGE_IDS = [
        'DT144_10:FAIL',
        'DT144_10:UC_XH6370',
        'DT144_10:SUCCESS',
    ];

    public function __construct(private readonly BitrixRest $bitrixRest) {}

    /**
     * Sync landlord contact TYPE_ID and apartment CRM fields from local apartments.
     *
     * @return array{
     *     landlords: int,
     *     checked: int,
     *     updated: int,
     *     unchanged: int,
     *     failed: int,
     *     dry_run: bool,
     *     failures: list<array{id:int,error:mixed}>
     * }
     */
    public function sync(bool $dryRun = false, ?int $limit = null): array
    {
        $desired = $this->buildDesiredState($limit);
        $stats = [
            'landlords' => count($desired),
            'checked' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'dry_run' => $dryRun,
            'failures' => [],
        ];

        if ($desired === []) {
            return $stats;
        }

        $fieldActive = (string) config('services.bitrix_landlord_contacts.field_active');
        $fieldEx = (string) config('services.bitrix_landlord_contacts.field_ex');
        $typeActual = (string) config('services.bitrix_landlord_contacts.type_actual');
        $typeEx = (string) config('services.bitrix_landlord_contacts.type_ex');
        $chunkSize = (int) config('services.bitrix_landlord_contacts.batch_size', 50);

        foreach (array_chunk(array_keys($desired), $chunkSize) as $idChunk) {
            $current = $this->fetchCurrent($idChunk, $fieldActive, $fieldEx);
            $toUpdate = [];

            foreach ($idChunk as $contactId) {
                $want = $desired[$contactId];
                $have = $current[$contactId] ?? [
                    'TYPE_ID' => '',
                    'active' => [],
                    'ex' => [],
                ];

                $stats['checked']++;

                $fields = [];
                $targetType = $want['active'] !== [] ? $typeActual : $typeEx;
                if ((string) $have['TYPE_ID'] !== $targetType) {
                    $fields['TYPE_ID'] = $targetType;
                }
                if ($have['active'] !== $want['active']) {
                    $fields[$fieldActive] = $want['active'];
                }
                if ($have['ex'] !== $want['ex']) {
                    $fields[$fieldEx] = $want['ex'];
                }

                if ($fields === []) {
                    $stats['unchanged']++;
                    continue;
                }

                $toUpdate[$contactId] = $fields;
            }

            if ($toUpdate === []) {
                continue;
            }

            if ($dryRun) {
                $stats['updated'] += count($toUpdate);
                Log::channel('bitrix_landlord_contacts')->info('Dry-run landlord contact updates', [
                    'count' => count($toUpdate),
                    'sample' => array_slice($toUpdate, 0, 5, true),
                ]);
                continue;
            }

            $result = $this->applyUpdates($toUpdate);
            $stats['updated'] += $result['ok'];
            $stats['failed'] += count($result['failures']);
            foreach ($result['failures'] as $failure) {
                $stats['failures'][] = $failure;
            }

            usleep(150000);
        }

        return $stats;
    }

    /**
     * @return array<int, array{active:list<string>,ex:list<string>}>
     */
    private function buildDesiredState(?int $limit): array
    {
        $workStageIds = DB::table('stages')
            ->where('entity_type', 'apartment')
            ->whereNotIn('bitrix_stage_id', self::EXCLUDED_STAGE_IDS)
            ->pluck('id')
            ->all();

        $exStageIds = DB::table('stages')
            ->where('entity_type', 'apartment')
            ->whereIn('bitrix_stage_id', self::EXCLUDED_STAGE_IDS)
            ->pluck('id')
            ->all();

        $rows = DB::table('apartments as a')
            ->join('contacts as c', 'c.id', '=', 'a.landlord_contact_id')
            ->where('a.is_deleted', false)
            ->whereNotNull('a.landlord_contact_id')
            ->whereNotNull('c.bitrix_id')
            ->select([
                'c.bitrix_id as contact_bitrix_id',
                'a.bitrix_id as apartment_bitrix_id',
                'a.stage_id',
            ])
            ->orderBy('c.bitrix_id')
            ->orderBy('a.bitrix_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $contactId = (int) $row->contact_bitrix_id;
            $apartmentId = (int) $row->apartment_bitrix_id;
            if ($contactId <= 0 || $apartmentId <= 0) {
                continue;
            }

            if (! isset($map[$contactId])) {
                $map[$contactId] = ['active' => [], 'ex' => []];
            }

            $stageId = (int) $row->stage_id;
            if (in_array($stageId, $workStageIds, true)) {
                $map[$contactId]['active'][$apartmentId] = (string) $apartmentId;
            } elseif (in_array($stageId, $exStageIds, true)) {
                $map[$contactId]['ex'][$apartmentId] = (string) $apartmentId;
            }
        }

        foreach ($map as $contactId => $buckets) {
            $active = array_values($buckets['active']);
            $ex = array_values($buckets['ex']);
            sort($active, SORT_NUMERIC);
            sort($ex, SORT_NUMERIC);
            $map[$contactId] = ['active' => $active, 'ex' => $ex];
        }

        if ($limit !== null && $limit > 0) {
            $map = array_slice($map, 0, $limit, true);
        }

        return $map;
    }

    /**
     * @param  list<int>  $contactIds
     * @return array<int, array{TYPE_ID:string,active:list<string>,ex:list<string>}>
     */
    private function fetchCurrent(array $contactIds, string $fieldActive, string $fieldEx): array
    {
        $response = $this->bitrixRest->postJson('crm.contact.list', [
            'filter' => ['@ID' => $contactIds],
            'select' => ['ID', 'TYPE_ID', $fieldActive, $fieldEx],
            'start' => 0,
        ]);

        $result = [];
        foreach (data_get($response, 'result', []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = (int) ($item['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $result[$id] = [
                'TYPE_ID' => (string) ($item['TYPE_ID'] ?? ''),
                'active' => $this->normalizeCrmIds($item[$fieldActive] ?? null),
                'ex' => $this->normalizeCrmIds($item[$fieldEx] ?? null),
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $updates
     * @return array{ok:int,failures:list<array{id:int,error:mixed}>}
     */
    private function applyUpdates(array $updates): array
    {
        $ok = 0;
        $failures = [];
        $ids = array_keys($updates);
        $cmd = [];

        foreach (array_values($ids) as $index => $contactId) {
            $cmd['c'.$index] = $this->buildUpdateCmd((int) $contactId, $updates[$contactId]);
        }

        $response = $this->bitrixRest->postJson('batch', [
            'halt' => 0,
            'cmd' => $cmd,
        ]);

        $errors = data_get($response, 'result.result_error', []);
        if (! is_array($errors)) {
            $errors = [];
        }

        foreach (array_values($ids) as $index => $contactId) {
            $key = 'c'.$index;
            if (! empty($errors[$key])) {
                $failures[] = ['id' => (int) $contactId, 'error' => $errors[$key]];
                Log::channel('bitrix_landlord_contacts')->warning('Landlord contact update failed', [
                    'contact_id' => $contactId,
                    'error' => $errors[$key],
                ]);
                continue;
            }
            $ok++;
        }

        return ['ok' => $ok, 'failures' => $failures];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function buildUpdateCmd(int $contactId, array $fields): string
    {
        $parts = ['ID='.$contactId];

        foreach ($fields as $key => $value) {
            $fieldKey = rawurlencode((string) $key);
            if (is_array($value)) {
                if ($value === []) {
                    $parts[] = 'fields['.$fieldKey.']=';
                    continue;
                }

                foreach (array_values($value) as $index => $item) {
                    $parts[] = 'fields['.$fieldKey.']['.$index.']='.rawurlencode((string) $item);
                }
                continue;
            }

            $parts[] = 'fields['.$fieldKey.']='.rawurlencode((string) $value);
        }

        return 'crm.contact.update?'.implode('&', $parts);
    }

    /**
     * @return list<string>
     */
    private function normalizeCrmIds(mixed $value): array
    {
        if ($value === null || $value === false || $value === '' || $value === []) {
            return [];
        }

        if (! is_array($value)) {
            $value = [$value];
        }

        $ids = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['ID'] ?? $item['id'] ?? null;
            }

            $raw = trim((string) $item);
            if ($raw === '') {
                continue;
            }

            if (preg_match('/_(\d+)$/', $raw, $matches) === 1) {
                $raw = $matches[1];
            }

            $id = (int) $raw;
            if ($id > 0) {
                $ids[$id] = (string) $id;
            }
        }

        $list = array_values($ids);
        sort($list, SORT_NUMERIC);

        return $list;
    }
}
