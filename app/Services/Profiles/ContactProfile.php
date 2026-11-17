<?php

namespace App\Services\Profiles;

use App\Models\Contact;
use App\Services\BitrixRest;
use App\Services\Contracts\EntityProfile;
use App\Support\BitrixDiff;
use App\Support\BitrixEnums;
use App\Support\BitrixSync;
use App\Support\BitrixValue;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ContactProfile implements EntityProfile
{
    private const UF_GENDER = 'UF_CRM_1688664973718';

    private const UF_LANGUAGE = 'UF_CRM_1696438640';

    private const UF_NATIONALITY = 'UF_CRM_1755104713';

    private const UF_NATIONALITY_OLD = 'UF_CRM_1729690794035';

    private const UPSERT_COLS = [
        'first_name',
        'last_name',
        'contact_type_id',
        'nationality',
        'birth_date',
        'gender',
        'language',
        'is_deleted',
        'bitrix_created_at',
        'bitrix_updated_at',
        'last_synced_at',
        'updated_at',
    ];

    private const LOG_KEYS = [
        'bitrix_id',
        'first_name',
        'last_name',
        'contact_type_id',
        'nationality',
        'birth_date',
        'gender',
        'language',
        'is_deleted',
        'bitrix_created_at',
        'bitrix_updated_at',
        'last_synced_at',
    ];

    /**
     * @var array<int, string|null>
     */
    private array $userNames = [];

    public function __construct(
        private readonly BitrixSync $syncContext,
        private readonly BitrixRest $bitrix
    ) {}

    /**
     * Bitrix crm.contact.list / get select fields.
     *
     * @return list<string>
     */
    public static function select(): array
    {
        return [
            'ID',
            'NAME',
            'LAST_NAME',
            'MODIFY_BY_ID',
            self::UF_GENDER,
            self::UF_LANGUAGE,
            self::UF_NATIONALITY,
            self::UF_NATIONALITY_OLD,
            'TYPE_ID',
            'BIRTHDATE',
            'PHONE',
            'EMAIL',
            'DATE_CREATE',
            'DATE_MODIFY',
        ];
    }

    public function entity(): string
    {
        return 'contacts';
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{processed:int, created:int, updated:int, successful:int, skipped:int, failed:int, failed_ids:list<int|string>}
     */
    public function syncBatch(array $items): array
    {
        $processed = 0;
        $created = 0;
        $updated = 0;
        $successful = 0;
        $skipped = 0;
        $failedIds = [];
        $now = now();
        $typeMap = $this->typeMap();
        $defaultTypeId = $typeMap['not_selected'] ?? null;
        $enums = BitrixEnums::loadContact($this->bitrix, [
            self::UF_GENDER,
            self::UF_LANGUAGE,
            self::UF_NATIONALITY,
            self::UF_NATIONALITY_OLD,
        ]);

        $incomingIds = [];
        foreach ($items as $item) {
            $id = (int) ($item['ID'] ?? 0);
            if ($id > 0) {
                $incomingIds[] = $id;
            }
        }

        $existing = collect();
        $knownIds = [];
        if ($incomingIds !== []) {
            $existing = Contact::query()
                ->whereIn('bitrix_id', $incomingIds)
                ->get([
                    'id',
                    'bitrix_id',
                    'first_name',
                    'last_name',
                    'contact_type_id',
                    'nationality',
                    'birth_date',
                    'gender',
                    'language',
                    'is_deleted',
                    'bitrix_created_at',
                    'bitrix_updated_at',
                    'last_synced_at',
                ])
                ->keyBy(static fn (Contact $contact): int => (int) $contact->bitrix_id);
            $knownIds = array_fill_keys(
                $existing->keys()->map(static fn (mixed $value): int => (int) $value)->all(),
                true
            );
        }

        $batch = [
            'records' => [],
            'batchIds' => [],
            'phonesById' => [],
            'emailsById' => [],
            'opsById' => [],
            'oldById' => [],
            'modifierIds' => [],
            'modifierNames' => [],
            'now' => $now,
        ];

        foreach ($items as $item) {
            $processed++;

            try {
                $normalized = $this->normalizeItem($item, $now, $typeMap, $defaultTypeId, $enums);
                $bitrixId = (int) $normalized['bitrix_id'];
                if (isset($knownIds[$bitrixId])) {
                    $updated++;
                    $batch['opsById'][$bitrixId] = 'bitrix.contact.updated';
                    $existingContact = $existing->get($bitrixId);
                    if ($existingContact instanceof Contact) {
                        $batch['oldById'][$bitrixId] = $this->snapshot($existingContact);
                    }
                } else {
                    $created++;
                    $batch['opsById'][$bitrixId] = 'bitrix.contact.created';
                }

                $batch['records'][] = $normalized;
                $batch['phonesById'][$bitrixId] = $this->normMulti($item['PHONE'] ?? [], 'phone', $this->normalizePhone(...));
                $batch['emailsById'][$bitrixId] = $this->normMulti($item['EMAIL'] ?? [], 'email', $this->normalizeEmail(...));
                $modifierId = BitrixValue::asInt($item['MODIFY_BY_ID'] ?? null);
                $batch['modifierIds'][$bitrixId] = $modifierId;
                $batch['modifierNames'][$bitrixId] = $this->userName($modifierId);
                $batch['batchIds'][] = $bitrixId;
                $successful++;
            } catch (\Throwable $e) {
                $failedIds[] = $item['ID'] ?? 'unknown';
                Log::warning('CONTACT_PROFILE_ITEM_FAILED', [
                    'bitrix_id' => $item['ID'] ?? null,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
            }
        }

        if ($batch['records'] !== []) {
            $this->upsertBatch($batch);
        }

        return [
            'processed' => $processed,
            'created' => $created,
            'updated' => $updated,
            'successful' => $successful,
            'skipped' => $skipped,
            'failed' => count($failedIds),
            'failed_ids' => $failedIds,
        ];
    }

    public function syncOne(int $bitrixId): bool
    {
        $item = $this->fetchOne($bitrixId);
        if ($item === null) {
            return false;
        }

        $result = $this->syncBatch([$item]);

        return $result['successful'] > 0 || $result['skipped'] > 0;
    }

    public function markDeleted(int $bitrixId): int
    {
        return Contact::query()
            ->where('bitrix_id', $bitrixId)
            ->update([
                'is_deleted' => true,
                'last_synced_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchOne(int $bitrixId): ?array
    {
        $response = $this->bitrix->post('crm.contact.get.json', ['id' => $bitrixId]);

        if (! $response->successful()) {
            return null;
        }

        $result = data_get($response->json(), 'result');

        return is_array($result) ? $result : null;
    }

    /**
     * @param  array{
     *     records: list<array<string, mixed>>,
     *     batchIds: list<int>,
     *     phonesById: array<int, list<array{phone?:string,email?:string,type:?string,is_primary:bool,sort:int}>>,
     *     emailsById: array<int, list<array{phone?:string,email?:string,type:?string,is_primary:bool,sort:int}>>,
     *     opsById: array<int, string>,
     *     oldById: array<int, array<string, mixed>>,
     *     modifierIds: array<int, int|null>,
     *     modifierNames: array<int, string|null>,
     *     now: Carbon
     * }  $batch
     */
    private function upsertBatch(array $batch): void
    {
        $this->syncContext->withoutPush(function () use ($batch): void {
            DB::transaction(function () use ($batch): void {
                Contact::query()->upsert(
                    $batch['records'],
                    ['bitrix_id'],
                    self::UPSERT_COLS
                );

                $localIds = Contact::query()
                    ->whereIn('bitrix_id', $batch['batchIds'])
                    ->pluck('id', 'bitrix_id');

                $contactIds = $localIds->values()->all();
                if ($contactIds !== []) {
                    DB::table('contact_phones')->whereIn('contact_id', $contactIds)->delete();
                    DB::table('contact_emails')->whereIn('contact_id', $contactIds)->delete();
                }

                $this->insertChildren('contact_phones', 'phone', $localIds, $batch['phonesById'], $batch['now']);
                $this->insertChildren('contact_emails', 'email', $localIds, $batch['emailsById'], $batch['now']);
                $this->logBatch($batch, $localIds);
            });
        });
    }

    /**
     * @param  array{
     *     records: list<array<string, mixed>>,
     *     opsById: array<int, string>,
     *     oldById: array<int, array<string, mixed>>,
     *     modifierIds: array<int, int|null>,
     *     modifierNames: array<int, string|null>,
     *     now: Carbon
     * }  $batch
     * @param  Collection<int|string, string>  $localIds
     */
    private function logBatch(array $batch, Collection $localIds): void
    {
        $rows = [];

        foreach ($batch['records'] as $record) {
            $bitrixId = (int) ($record['bitrix_id'] ?? 0);
            $contactId = $localIds->get($bitrixId);

            if (! is_string($contactId) || $contactId === '') {
                continue;
            }

            $event = $batch['opsById'][$bitrixId] ?? 'bitrix.contact.synced';
            $newValues = Arr::only($record, self::LOG_KEYS);
            $newValues['birth_date'] = $this->formatDate($newValues['birth_date'] ?? null);
            $newValues['changed_by_bitrix_user_id'] = $batch['modifierIds'][$bitrixId] ?? null;
            $newValues['changed_by_bitrix_user_name'] = $batch['modifierNames'][$bitrixId] ?? null;
            $oldValues = $event === 'bitrix.contact.updated' ? ($batch['oldById'][$bitrixId] ?? null) : null;
            if ($event === 'bitrix.contact.updated' && ! $this->changed($oldValues, $newValues)) {
                continue;
            }

            $rows[] = [
                'id' => (string) Str::uuid(),
                'event' => $event,
                'subject_type' => Contact::class,
                'subject_id' => $contactId,
                'user_id' => null,
                'old_values' => $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'new_values' => json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'happened_at' => $batch['now'],
                'created_at' => $batch['now'],
                'updated_at' => $batch['now'],
            ];
        }

        if ($rows !== []) {
            DB::table('activity_logs')->insert($rows);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Contact $contact): array
    {
        return [
            'bitrix_id' => $contact->bitrix_id !== null ? (int) $contact->bitrix_id : null,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'contact_type_id' => $contact->contact_type_id,
            'nationality' => $contact->nationality,
            'birth_date' => $this->formatDate($contact->birth_date),
            'gender' => $contact->gender,
            'language' => $contact->language,
            'is_deleted' => (bool) $contact->is_deleted,
            'bitrix_created_at' => $contact->bitrix_created_at?->toIso8601String(),
            'bitrix_updated_at' => $contact->bitrix_updated_at?->toIso8601String(),
            'last_synced_at' => $contact->last_synced_at?->toIso8601String(),
            'changed_by_bitrix_user_id' => null,
            'changed_by_bitrix_user_name' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function changed(?array $oldValues, array $newValues): bool
    {
        return BitrixDiff::changed($oldValues, $newValues, [
            'bitrix_created_at',
            'bitrix_updated_at',
            'last_synced_at',
            'changed_by_bitrix_user_id',
            'changed_by_bitrix_user_name',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function typeMap(): array
    {
        $map = [];
        foreach (DB::table('contact_types')->get(['id', 'code']) as $row) {
            $map[$this->normalizeTypeKey($row->code)] = (int) $row->id;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $typeMap
     * @param  array<string, array<string, string>>  $enums
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item, Carbon $now, array $typeMap, ?int $defaultTypeId, array $enums): array
    {
        $bitrixId = (int) ($item['ID'] ?? 0);
        if ($bitrixId <= 0) {
            throw new \RuntimeException('Invalid Bitrix id');
        }

        $typeKey = $this->normalizeTypeKey((string) ($item['TYPE_ID'] ?? ''));
        $nationalityRaw = $item[self::UF_NATIONALITY] ?? $item[self::UF_NATIONALITY_OLD] ?? null;

        return [
            'id' => (string) Str::uuid(),
            'bitrix_id' => $bitrixId,
            'first_name' => BitrixValue::str($item['NAME'] ?? null),
            'last_name' => BitrixValue::str($item['LAST_NAME'] ?? null),
            'contact_type_id' => $typeMap[$typeKey] ?? $defaultTypeId,
            'nationality' => BitrixEnums::resolve($enums, self::UF_NATIONALITY, $nationalityRaw)
                ?? BitrixEnums::resolve($enums, self::UF_NATIONALITY_OLD, $nationalityRaw),
            'birth_date' => BitrixValue::dt($item['BIRTHDATE'] ?? null)?->startOfDay(),
            'gender' => BitrixEnums::resolve($enums, self::UF_GENDER, $item[self::UF_GENDER] ?? null),
            'language' => BitrixEnums::resolve($enums, self::UF_LANGUAGE, $item[self::UF_LANGUAGE] ?? null),
            'is_deleted' => false,
            'bitrix_created_at' => BitrixValue::dt($item['DATE_CREATE'] ?? null),
            'bitrix_updated_at' => BitrixValue::dt($item['DATE_MODIFY'] ?? null),
            'last_synced_at' => $now,
            'updated_at' => $now,
            'created_at' => $now,
        ];
    }

    /**
     * @param  callable(string): ?string  $normalize
     * @return list<array{type:?string,is_primary:bool,sort:int}&array<string, string>>
     */
    private function normMulti(mixed $rows, string $column, callable $normalize): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $result = [];
        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $value = $normalize((string) ($row['VALUE'] ?? ''));
            if ($value === null) {
                continue;
            }

            $result[] = [
                $column => $value,
                'type' => BitrixValue::str($row['VALUE_TYPE'] ?? null),
                'is_primary' => $index === 0,
                'sort' => 100 + ($index * 100),
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int|string, string>  $localIds
     * @param  array<int, list<array<string, mixed>>>  $rowsById
     */
    private function insertChildren(
        string $table,
        string $column,
        Collection $localIds,
        array $rowsById,
        Carbon $now
    ): void {
        $rows = [];
        foreach ($rowsById as $bitrixId => $childRows) {
            $contactId = $localIds->get($bitrixId);
            if (! is_string($contactId) || $contactId === '') {
                continue;
            }

            foreach ($childRows as $row) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'contact_id' => $contactId,
                    $column => $row[$column],
                    'type' => $row['type'],
                    'is_primary' => $row['is_primary'],
                    'sort' => $row['sort'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            DB::table($table)->insert($rows);
        }
    }

    private function normalizeTypeKey(string $value): string
    {
        $normalized = Str::of($value)->trim()->lower()->replace('-', '_')->replace(' ', '_')->value();

        return preg_replace('/[^a-z0-9_]/', '', $normalized) ?? '';
    }

    private function normalizePhone(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $normalized = preg_replace('/[^\d+]+/', '', $trimmed);

        return ($normalized === null || $normalized === '') ? null : $normalized;
    }

    private function normalizeEmail(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $email = strtolower($trimmed);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return BitrixValue::str($value);
        }
    }

    private function userName(?int $userId): ?string
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }

        if (array_key_exists($userId, $this->userNames)) {
            return $this->userNames[$userId];
        }

        try {
            $response = $this->bitrix->postJson('user.get', [
                'FILTER' => ['ID' => $userId],
            ]);
            $users = data_get($response, 'result', []);
            if (! is_array($users) || $users === [] || ! is_array($users[0] ?? null)) {
                $this->userNames[$userId] = null;

                return null;
            }

            $firstName = BitrixValue::str($users[0]['NAME'] ?? null);
            $lastName = BitrixValue::str($users[0]['LAST_NAME'] ?? null);
            $fullName = trim(($firstName ?? '').' '.($lastName ?? ''));
            $resolved = $fullName !== '' ? $fullName : (BitrixValue::str($users[0]['EMAIL'] ?? null) ?? null);
            $this->userNames[$userId] = $resolved;

            return $resolved;
        } catch (\Throwable) {
            $this->userNames[$userId] = null;

            return null;
        }
    }
}
