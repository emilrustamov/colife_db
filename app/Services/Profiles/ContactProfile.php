<?php

namespace App\Services\Profiles;

use App\Models\Contact;
use App\Services\BitrixRest;
use App\Services\Contracts\EntityProfile;
use App\Support\BitrixDiff;
use App\Support\BitrixSync;
use App\Support\BitrixValue;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContactProfile implements EntityProfile
{
    private const BITRIX_FIELD_GENDER = 'UF_CRM_1688664973718';

    private const BITRIX_FIELD_LANGUAGE = 'UF_CRM_1696438640';

    private const BITRIX_FIELD_NATIONALITY = 'UF_CRM_1755104713';

    private const BITRIX_FIELD_NATIONALITY_LEGACY = 'UF_CRM_1729690794035';

    /**
     * @var array<int, string|null>
     */
    private array $bitrixUserNameCache = [];

    public function __construct(
        private readonly BitrixSync $syncContext,
        private readonly BitrixRest $bitrixRestClient
    ) {}

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
        $typeMap = $this->resolveTypeMap();
        $defaultTypeId = $typeMap['not_selected'] ?? null;
        $enumMap = $this->contactEnums([
            self::BITRIX_FIELD_GENDER,
            self::BITRIX_FIELD_LANGUAGE,
            self::BITRIX_FIELD_NATIONALITY,
            self::BITRIX_FIELD_NATIONALITY_LEGACY,
        ]);

        $incomingBitrixIds = [];
        foreach ($items as $item) {
            $incomingBitrixId = (int) ($item['ID'] ?? 0);
            if ($incomingBitrixId > 0) {
                $incomingBitrixIds[] = $incomingBitrixId;
            }
        }

        $existingBitrixIds = [];
        $existingContactsByBitrixId = collect();
        if ($incomingBitrixIds !== []) {
            $existingContactsByBitrixId = Contact::query()
                ->whereIn('bitrix_id', $incomingBitrixIds)
                ->get([
                    'id',
                    'bitrix_id',
                    'first_name',
                    'last_name',
                    'contact_type_id',
                    'birth_date',
                    'is_deleted',
                    'bitrix_created_at',
                    'bitrix_updated_at',
                    'last_synced_at',
                ])
                ->keyBy(static fn (Contact $contact): int => (int) $contact->bitrix_id);
            $existingBitrixIds = $existingContactsByBitrixId
                ->keys()
                ->map(static fn (mixed $value): int => (int) $value)
                ->all();
            $existingBitrixIds = array_fill_keys($existingBitrixIds, true);
        }

        $recordsPayload = [];
        $phonePayloadByBitrixId = [];
        $emailPayloadByBitrixId = [];
        $currentBatchBitrixIds = [];
        $operationByBitrixId = [];
        $oldValuesByBitrixId = [];
        $changedByBitrixUserIdByBitrixId = [];
        $changedByBitrixUserNameByBitrixId = [];

        foreach ($items as $item) {
            $processed++;

            try {
                $normalized = $this->normalizeItem($item, $now, $typeMap, $defaultTypeId, $enumMap);
                $bitrixId = (int) $normalized['bitrix_id'];
                if (isset($existingBitrixIds[$bitrixId])) {
                    $updated++;
                    $operationByBitrixId[$bitrixId] = 'bitrix.contact.updated';
                    $existingContact = $existingContactsByBitrixId->get($bitrixId);
                    if ($existingContact instanceof Contact) {
                        $oldValuesByBitrixId[$bitrixId] = $this->logPayload($existingContact);
                    }
                } else {
                    $created++;
                    $operationByBitrixId[$bitrixId] = 'bitrix.contact.created';
                }

                $recordsPayload[] = $normalized;
                $phonePayloadByBitrixId[$bitrixId] = $this->normPhones($item['PHONE'] ?? []);
                $emailPayloadByBitrixId[$bitrixId] = $this->normEmails($item['EMAIL'] ?? []);
                $changedByBitrixUserId = BitrixValue::asInt($item['MODIFY_BY_ID'] ?? null);
                $changedByBitrixUserIdByBitrixId[$bitrixId] = $changedByBitrixUserId;
                $changedByBitrixUserNameByBitrixId[$bitrixId] = $this->resolveBitrixUserName($changedByBitrixUserId);
                $currentBatchBitrixIds[] = $bitrixId;
                $successful++;
            } catch (\Throwable) {
                $failedIds[] = $item['ID'] ?? 'unknown';
            }
        }

        if ($recordsPayload !== []) {
            $this->upsertBatchPayload(
                $recordsPayload,
                $currentBatchBitrixIds,
                $phonePayloadByBitrixId,
                $emailPayloadByBitrixId,
                $operationByBitrixId,
                $oldValuesByBitrixId,
                $changedByBitrixUserIdByBitrixId,
                $changedByBitrixUserNameByBitrixId,
                $now
            );
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
        $item = $this->fetchItemByBitrixId($bitrixId);
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
    private function fetchItemByBitrixId(int $bitrixId): ?array
    {
        $response = $this->bitrixRestClient->post('crm.contact.get.json', ['id' => $bitrixId]);

        if (! $response->successful()) {
            return null;
        }

        $result = data_get($response->json(), 'result');

        return is_array($result) ? $result : null;
    }

    /**
     * @param  list<array<string, mixed>>  $recordsPayload
     * @param  list<int>  $currentBatchBitrixIds
     * @param  array<int, list<array{phone:string,type:?string,is_primary:bool,sort:int}>>  $phonePayloadByBitrixId
     * @param  array<int, list<array{email:string,type:?string,is_primary:bool,sort:int}>>  $emailPayloadByBitrixId
     * @param  array<int, string>  $operationByBitrixId
     * @param  array<int, array<string, mixed>>  $oldValuesByBitrixId
     * @param  array<int, int|null>  $changedByBitrixUserIdByBitrixId
     * @param  array<int, string|null>  $changedByBitrixUserNameByBitrixId
     */
    private function upsertBatchPayload(
        array $recordsPayload,
        array $currentBatchBitrixIds,
        array $phonePayloadByBitrixId,
        array $emailPayloadByBitrixId,
        array $operationByBitrixId,
        array $oldValuesByBitrixId,
        array $changedByBitrixUserIdByBitrixId,
        array $changedByBitrixUserNameByBitrixId,
        Carbon $now
    ): void {
        $this->syncContext->withoutPush(function () use ($recordsPayload, $currentBatchBitrixIds, $phonePayloadByBitrixId, $emailPayloadByBitrixId, $operationByBitrixId, $oldValuesByBitrixId, $changedByBitrixUserIdByBitrixId, $changedByBitrixUserNameByBitrixId, $now): void {
            DB::transaction(function () use ($recordsPayload, $currentBatchBitrixIds, $phonePayloadByBitrixId, $emailPayloadByBitrixId, $operationByBitrixId, $oldValuesByBitrixId, $changedByBitrixUserIdByBitrixId, $changedByBitrixUserNameByBitrixId, $now): void {
                Contact::query()->upsert(
                    $recordsPayload,
                    ['bitrix_id'],
                    [
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
                    ]
                );

                $contactIdByBitrixId = Contact::query()
                    ->whereIn('bitrix_id', $currentBatchBitrixIds)
                    ->pluck('id', 'bitrix_id');

                $contactIds = $contactIdByBitrixId->values()->all();
                if ($contactIds !== []) {
                    DB::table('contact_phones')->whereIn('contact_id', $contactIds)->delete();
                    DB::table('contact_emails')->whereIn('contact_id', $contactIds)->delete();
                }

                $this->insertPhones($contactIdByBitrixId, $phonePayloadByBitrixId, $now);
                $this->insertEmails($contactIdByBitrixId, $emailPayloadByBitrixId, $now);
                $this->insertActivityLogs(
                    $recordsPayload,
                    $contactIdByBitrixId,
                    $operationByBitrixId,
                    $oldValuesByBitrixId,
                    $changedByBitrixUserIdByBitrixId,
                    $changedByBitrixUserNameByBitrixId,
                    $now
                );
            });
        });
    }

    /**
     * @param  list<array<string, mixed>>  $recordsPayload
     * @param  Collection<int|string, string>  $contactIdByBitrixId
     * @param  array<int, string>  $operationByBitrixId
     * @param  array<int, array<string, mixed>>  $oldValuesByBitrixId
     * @param  array<int, int|null>  $changedByBitrixUserIdByBitrixId
     * @param  array<int, string|null>  $changedByBitrixUserNameByBitrixId
     */
    private function insertActivityLogs(
        array $recordsPayload,
        Collection $contactIdByBitrixId,
        array $operationByBitrixId,
        array $oldValuesByBitrixId,
        array $changedByBitrixUserIdByBitrixId,
        array $changedByBitrixUserNameByBitrixId,
        Carbon $now
    ): void {
        $rows = [];

        foreach ($recordsPayload as $record) {
            $bitrixId = (int) ($record['bitrix_id'] ?? 0);
            $contactId = $contactIdByBitrixId->get($bitrixId);

            if (! is_string($contactId) || $contactId === '') {
                continue;
            }

            $event = $operationByBitrixId[$bitrixId] ?? 'bitrix.contact.synced';
            $newValues = Arr::only($record, [
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
            ]);
            $newValues['birth_date'] = $this->formatDateOnly($newValues['birth_date'] ?? null);
            $newValues['changed_by_bitrix_user_id'] = $changedByBitrixUserIdByBitrixId[$bitrixId] ?? null;
            $newValues['changed_by_bitrix_user_name'] = $changedByBitrixUserNameByBitrixId[$bitrixId] ?? null;
            $oldValues = $event === 'bitrix.contact.updated' ? ($oldValuesByBitrixId[$bitrixId] ?? null) : null;
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
                'happened_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('activity_logs')->insert($rows);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function logPayload(Contact $contact): array
    {
        return [
            'bitrix_id' => $contact->bitrix_id !== null ? (int) $contact->bitrix_id : null,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'contact_type_id' => $contact->contact_type_id,
            'nationality' => $contact->nationality,
            'birth_date' => $this->formatDateOnly($contact->birth_date),
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
    private function resolveTypeMap(): array
    {
        /** @var Collection<int, object{code:string,id:int}> $rows */
        $rows = DB::table('contact_types')->get(['id', 'code']);
        $map = [];

        foreach ($rows as $row) {
            $map[$this->normalizeTypeKey($row->code)] = (int) $row->id;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $typeMap
     * @param  array<string, array<string, string>>  $enumMap
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item, Carbon $syncedAt, array $typeMap, ?int $defaultTypeId, array $enumMap): array
    {
        $bitrixId = (int) ($item['ID'] ?? 0);
        if ($bitrixId <= 0) {
            throw new \RuntimeException('Invalid Bitrix id');
        }

        $typeKey = $this->normalizeTypeKey((string) ($item['TYPE_ID'] ?? ''));
        $nationalityRaw = $item[self::BITRIX_FIELD_NATIONALITY] ?? $item[self::BITRIX_FIELD_NATIONALITY_LEGACY] ?? null;

        return [
            'id' => (string) Str::uuid(),
            'bitrix_id' => $bitrixId,
            'first_name' => BitrixValue::str($item['NAME'] ?? null),
            'last_name' => BitrixValue::str($item['LAST_NAME'] ?? null),
            'contact_type_id' => $typeMap[$typeKey] ?? $defaultTypeId,
            'nationality' => $this->resolveEnumDisplayValue($enumMap, self::BITRIX_FIELD_NATIONALITY, $nationalityRaw)
                ?? $this->resolveEnumDisplayValue($enumMap, self::BITRIX_FIELD_NATIONALITY_LEGACY, $nationalityRaw),
            'birth_date' => $this->parseDate($item['BIRTHDATE'] ?? null),
            'gender' => $this->resolveEnumDisplayValue($enumMap, self::BITRIX_FIELD_GENDER, $item[self::BITRIX_FIELD_GENDER] ?? null),
            'language' => $this->resolveEnumDisplayValue($enumMap, self::BITRIX_FIELD_LANGUAGE, $item[self::BITRIX_FIELD_LANGUAGE] ?? null),
            'is_deleted' => false,
            'bitrix_created_at' => $this->parseDateTime($item['DATE_CREATE'] ?? null),
            'bitrix_updated_at' => $this->parseDateTime($item['DATE_MODIFY'] ?? null),
            'last_synced_at' => $syncedAt,
            'updated_at' => $syncedAt,
            'created_at' => $syncedAt,
        ];
    }

    /**
     * @return list<array{phone:string,type:?string,is_primary:bool,sort:int}>
     */
    private function normPhones(mixed $phones): array
    {
        if (! is_array($phones)) {
            return [];
        }

        $result = [];
        foreach (array_values($phones) as $index => $phoneRow) {
            if (! is_array($phoneRow)) {
                continue;
            }

            $phone = $this->normalizePhone((string) ($phoneRow['VALUE'] ?? ''));
            if ($phone === null) {
                continue;
            }

            $result[] = [
                'phone' => $phone,
                'type' => BitrixValue::str($phoneRow['VALUE_TYPE'] ?? null),
                'is_primary' => $index === 0,
                'sort' => 100 + ($index * 100),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{email:string,type:?string,is_primary:bool,sort:int}>
     */
    private function normEmails(mixed $emails): array
    {
        if (! is_array($emails)) {
            return [];
        }

        $result = [];
        foreach (array_values($emails) as $index => $emailRow) {
            if (! is_array($emailRow)) {
                continue;
            }

            $email = $this->normalizeEmail((string) ($emailRow['VALUE'] ?? ''));
            if ($email === null) {
                continue;
            }

            $result[] = [
                'email' => $email,
                'type' => BitrixValue::str($emailRow['VALUE_TYPE'] ?? null),
                'is_primary' => $index === 0,
                'sort' => 100 + ($index * 100),
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int|string, string>  $contactIdByBitrixId
     * @param  array<int, list<array{phone:string,type:?string,is_primary:bool,sort:int}>>  $phonePayloadByBitrixId
     */
    private function insertPhones(Collection $contactIdByBitrixId, array $phonePayloadByBitrixId, Carbon $now): void
    {
        $rows = [];
        foreach ($phonePayloadByBitrixId as $bitrixId => $phoneRows) {
            $contactId = $contactIdByBitrixId->get($bitrixId);
            if (! is_string($contactId) || $contactId === '') {
                continue;
            }

            foreach ($phoneRows as $row) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'contact_id' => $contactId,
                    'phone' => $row['phone'],
                    'type' => $row['type'],
                    'is_primary' => $row['is_primary'],
                    'sort' => $row['sort'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('contact_phones')->insert($rows);
        }
    }

    /**
     * @param  Collection<int|string, string>  $contactIdByBitrixId
     * @param  array<int, list<array{email:string,type:?string,is_primary:bool,sort:int}>>  $emailPayloadByBitrixId
     */
    private function insertEmails(Collection $contactIdByBitrixId, array $emailPayloadByBitrixId, Carbon $now): void
    {
        $rows = [];
        foreach ($emailPayloadByBitrixId as $bitrixId => $emailRows) {
            $contactId = $contactIdByBitrixId->get($bitrixId);
            if (! is_string($contactId) || $contactId === '') {
                continue;
            }

            foreach ($emailRows as $row) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'contact_id' => $contactId,
                    'email' => $row['email'],
                    'type' => $row['type'],
                    'is_primary' => $row['is_primary'],
                    'sort' => $row['sort'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('contact_emails')->insert($rows);
        }
    }

    private function normalizeTypeKey(string $value): string
    {
        $normalized = Str::of($value)->trim()->lower()->replace('-', '_')->replace(' ', '_')->value();

        return preg_replace('/[^a-z0-9_]/', '', $normalized) ?? '';
    }

    /**
     * @param  list<string>  $fieldCodes
     * @return array<string, array<string, string>>
     */
    private function contactEnums(array $fieldCodes): array
    {
        $response = $this->bitrixRestClient->postJson('crm.contact.fields', []);
        $fields = data_get($response, 'result', []);
        if (! is_array($fields)) {
            return [];
        }

        $map = [];
        foreach ($fieldCodes as $fieldCode) {
            $definition = $fields[$fieldCode] ?? null;
            if (! is_array($definition)) {
                continue;
            }

            $items = $definition['items'] ?? null;
            if (! is_array($items)) {
                continue;
            }

            $fieldMap = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = BitrixValue::str($item['ID'] ?? null);
                $value = BitrixValue::str($item['VALUE'] ?? null);
                if ($id === null || $value === null) {
                    continue;
                }

                $fieldMap[$id] = $value;
            }

            if ($fieldMap !== []) {
                $map[$fieldCode] = $fieldMap;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, string>>  $enumMap
     */
    private function resolveEnumDisplayValue(array $enumMap, string $fieldCode, mixed $rawValue): ?string
    {
        $raw = BitrixValue::str($rawValue);
        if ($raw === null) {
            return null;
        }

        return $enumMap[$fieldCode][$raw] ?? $raw;
    }


    private function parseDate(mixed $value): ?Carbon
    {
        $string = BitrixValue::str($value);
        if ($string === null) {
            return null;
        }

        return Carbon::parse($string)->startOfDay();
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        $string = BitrixValue::str($value);
        if ($string === null) {
            return null;
        }

        return Carbon::parse($string);
    }

    private function normalizePhone(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $normalized = preg_replace('/[^\d+]+/', '', $trimmed);
        if ($normalized === null || $normalized === '') {
            return null;
        }

        return $normalized;
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


    private function formatDateOnly(mixed $value): ?string
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

    private function resolveBitrixUserName(?int $bitrixUserId): ?string
    {
        if ($bitrixUserId === null || $bitrixUserId <= 0) {
            return null;
        }

        if (array_key_exists($bitrixUserId, $this->bitrixUserNameCache)) {
            return $this->bitrixUserNameCache[$bitrixUserId];
        }

        try {
            $response = $this->bitrixRestClient->postJson('user.get', [
                'FILTER' => ['ID' => $bitrixUserId],
            ]);
            $users = data_get($response, 'result', []);
            if (! is_array($users) || $users === [] || ! is_array($users[0] ?? null)) {
                $this->bitrixUserNameCache[$bitrixUserId] = null;

                return null;
            }

            $firstName = BitrixValue::str($users[0]['NAME'] ?? null);
            $lastName = BitrixValue::str($users[0]['LAST_NAME'] ?? null);
            $fullName = trim(($firstName ?? '').' '.($lastName ?? ''));
            $resolved = $fullName !== '' ? $fullName : (BitrixValue::str($users[0]['EMAIL'] ?? null) ?? null);
            $this->bitrixUserNameCache[$bitrixUserId] = $resolved;

            return $resolved;
        } catch (\Throwable) {
            $this->bitrixUserNameCache[$bitrixUserId] = null;

            return null;
        }
    }
}
