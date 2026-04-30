<?php

namespace App\Services\Profiles;

use App\Models\Contact;
use App\Services\BitrixRestClient;
use App\Services\Contracts\BitrixEntityProfile;
use App\Support\BitrixSyncContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BitrixContactProfile implements BitrixEntityProfile
{
    public function __construct(
        private readonly BitrixSyncContext $syncContext,
        private readonly BitrixRestClient $bitrixRestClient
    ) {
    }

    public function entity(): string
    {
        return 'contacts';
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{processed:int, successful:int, skipped:int, failed:int, failed_ids:list<int|string>}
     */
    public function syncBatchItems(array $items): array
    {
        $processed = 0;
        $successful = 0;
        $skipped = 0;
        $failedIds = [];
        $now = now();
        $typeMap = $this->resolveTypeMap();
        $defaultTypeId = $typeMap['not_selected'] ?? null;

        $incomingBitrixIds = [];
        foreach ($items as $item) {
            $incomingBitrixId = (int) ($item['ID'] ?? 0);
            if ($incomingBitrixId > 0) {
                $incomingBitrixIds[] = $incomingBitrixId;
            }
        }

        $existingUpdatedAtByBitrixId = [];
        if ($incomingBitrixIds !== []) {
            $existingUpdatedAtByBitrixId = Contact::query()
                ->whereIn('bitrix_id', $incomingBitrixIds)
                ->get(['bitrix_id', 'bitrix_updated_at'])
                ->mapWithKeys(static function (Contact $contact): array {
                    return [(int) $contact->bitrix_id => $contact->bitrix_updated_at?->getTimestamp()];
                })
                ->all();
        }

        $recordsPayload = [];
        $phonePayloadByBitrixId = [];
        $emailPayloadByBitrixId = [];
        $currentBatchBitrixIds = [];

        foreach ($items as $item) {
            $processed++;

            try {
                $normalized = $this->normalizeItem($item, $now, $typeMap, $defaultTypeId);
                $bitrixId = (int) $normalized['bitrix_id'];
                $incomingUpdatedAt = $normalized['bitrix_updated_at'] instanceof Carbon
                    ? $normalized['bitrix_updated_at']->getTimestamp()
                    : null;

                if ($this->shouldSkipItem($bitrixId, $incomingUpdatedAt, $existingUpdatedAtByBitrixId)) {
                    $skipped++;
                    continue;
                }

                $recordsPayload[] = $normalized;
                $phonePayloadByBitrixId[$bitrixId] = $this->normalizePhoneCollection($item['PHONE'] ?? []);
                $emailPayloadByBitrixId[$bitrixId] = $this->normalizeEmailCollection($item['EMAIL'] ?? []);
                $currentBatchBitrixIds[] = $bitrixId;
                $successful++;
            } catch (\Throwable) {
                $failedIds[] = $item['ID'] ?? 'unknown';
            }
        }

        if ($recordsPayload !== []) {
            $this->upsertBatchPayload($recordsPayload, $currentBatchBitrixIds, $phonePayloadByBitrixId, $emailPayloadByBitrixId, $now);
        }

        return [
            'processed' => $processed,
            'successful' => $successful,
            'skipped' => $skipped,
            'failed' => count($failedIds),
            'failed_ids' => $failedIds,
        ];
    }

    public function syncSingleItemByBitrixId(int $bitrixId): bool
    {
        $item = $this->fetchItemByBitrixId($bitrixId);
        if ($item === null) {
            return false;
        }

        $result = $this->syncBatchItems([$item]);

        return $result['successful'] > 0 || $result['skipped'] > 0;
    }

    public function markItemDeleted(int $bitrixId): int
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
     */
    private function upsertBatchPayload(
        array $recordsPayload,
        array $currentBatchBitrixIds,
        array $phonePayloadByBitrixId,
        array $emailPayloadByBitrixId,
        Carbon $now
    ): void {
        $this->syncContext->runWithoutContactPush(function () use ($recordsPayload, $currentBatchBitrixIds, $phonePayloadByBitrixId, $emailPayloadByBitrixId, $now): void {
            DB::transaction(function () use ($recordsPayload, $currentBatchBitrixIds, $phonePayloadByBitrixId, $emailPayloadByBitrixId, $now): void {
                Contact::query()->upsert(
                    $recordsPayload,
                    ['bitrix_id'],
                    [
                        'first_name',
                        'last_name',
                        'contact_type_id',
                        'birth_date',
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
            });
        });
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
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item, Carbon $syncedAt, array $typeMap, ?int $defaultTypeId): array
    {
        $bitrixId = (int) ($item['ID'] ?? 0);
        if ($bitrixId <= 0) {
            throw new \RuntimeException('Invalid Bitrix id');
        }

        $typeKey = $this->normalizeTypeKey((string) ($item['TYPE_ID'] ?? ''));

        return [
            'id' => (string) Str::uuid(),
            'bitrix_id' => $bitrixId,
            'first_name' => $this->toNullableString($item['NAME'] ?? null),
            'last_name' => $this->toNullableString($item['LAST_NAME'] ?? null),
            'contact_type_id' => $typeMap[$typeKey] ?? $defaultTypeId,
            'birth_date' => $this->parseDate($item['BIRTHDATE'] ?? null),
            'is_deleted' => false,
            'bitrix_created_at' => $this->parseDateTime($item['DATE_CREATE'] ?? null),
            'bitrix_updated_at' => $this->parseDateTime($item['DATE_MODIFY'] ?? null),
            'last_synced_at' => $syncedAt,
            'updated_at' => $syncedAt,
            'created_at' => $syncedAt,
        ];
    }

    /**
     * @param  mixed  $phones
     * @return list<array{phone:string,type:?string,is_primary:bool,sort:int}>
     */
    private function normalizePhoneCollection(mixed $phones): array
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
                'type' => $this->toNullableString($phoneRow['VALUE_TYPE'] ?? null),
                'is_primary' => $index === 0,
                'sort' => 100 + ($index * 100),
            ];
        }

        return $result;
    }

    /**
     * @param  mixed  $emails
     * @return list<array{email:string,type:?string,is_primary:bool,sort:int}>
     */
    private function normalizeEmailCollection(mixed $emails): array
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
                'type' => $this->toNullableString($emailRow['VALUE_TYPE'] ?? null),
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

    /**
     * @param  array<int, int|null>  $existingUpdatedAtByBitrixId
     */
    private function shouldSkipItem(int $bitrixId, ?int $incomingUpdatedAt, array $existingUpdatedAtByBitrixId): bool
    {
        if (! array_key_exists($bitrixId, $existingUpdatedAtByBitrixId)) {
            return false;
        }

        $existingUpdatedAt = $existingUpdatedAtByBitrixId[$bitrixId] ?? null;
        if ($incomingUpdatedAt === null || $existingUpdatedAt === null) {
            return false;
        }

        return $incomingUpdatedAt <= $existingUpdatedAt;
    }

    private function normalizeTypeKey(string $value): string
    {
        $normalized = Str::of($value)->trim()->lower()->replace('-', '_')->replace(' ', '_')->value();

        return preg_replace('/[^a-z0-9_]/', '', $normalized) ?? '';
    }

    private function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        $string = $this->toNullableString($value);
        if ($string === null) {
            return null;
        }

        return Carbon::parse($string)->startOfDay();
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        $string = $this->toNullableString($value);
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
}
