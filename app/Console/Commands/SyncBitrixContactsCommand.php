<?php

namespace App\Console\Commands;

use App\Models\Contact;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SyncBitrixContactsCommand extends Command
{
    protected $signature = 'bitrix:sync-contacts';

    protected $description = 'Sync contacts from Bitrix24 to local database';

    /**
     * @return int
     */
    public function handle(): int
    {
        $this->info('Bitrix contacts sync started...');

        try {
            $result = $this->syncContacts();

            Log::channel('bitrix_contacts')->info('Bitrix contacts sync completed', $result);

            $this->info(sprintf(
                'Completed. Total: %d, successful: %d, failed: %d.',
                $result['total'],
                $result['successful'],
                $result['failed']
            ));

            if ($result['failed'] > 0) {
                $this->warn('Failed contact ids: '.implode(', ', $result['failed_contact_ids']));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::channel('bitrix_contacts')->error('Bitrix contacts sync failed', [
                'error' => $e->getMessage(),
            ]);

            $this->error('Bitrix contacts sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array{total:int, successful:int, failed:int, failed_contact_ids:list<int|string>}
     */
    private function syncContacts(): array
    {
        $start = 0;
        $total = 0;
        $successful = 0;
        $failedContactIds = [];
        $typeMap = $this->resolveContactTypeMap();
        $defaultContactTypeId = $this->resolveDefaultContactTypeId($typeMap);

        while (true) {
            $response = Http::timeout((int) config('services.bitrix_contacts.timeout', 60))
                ->acceptJson()
                ->asJson()
                ->post($this->buildUrl('crm.contact.list.json'), [
                    'select' => [
                        'ID',
                        'NAME',
                        'LAST_NAME',
                        'TYPE_ID',
                        'BIRTHDATE',
                        'PHONE',
                        'EMAIL',
                        'DATE_CREATE',
                        'DATE_MODIFY',
                    ],
                    'order' => ['ID' => 'ASC'],
                    'start' => $start,
                ]);

            $response->throw();

            /** @var list<array<string, mixed>> $items */
            $items = data_get($response->json(), 'result', []);

            if ($items === []) {
                break;
            }

            $now = now();
            $contactsPayload = [];
            $phonePayloadByBitrixId = [];
            $emailPayloadByBitrixId = [];
            $currentBatchBitrixIds = [];

            foreach ($items as $item) {
                $total++;

                try {
                    $normalized = $this->normalizeContact($item, $now, $typeMap, $defaultContactTypeId);
                    $bitrixId = $normalized['bitrix_id'];

                    $contactsPayload[] = $normalized;
                    $phonePayloadByBitrixId[$bitrixId] = $this->normalizePhones($item['PHONE'] ?? []);
                    $emailPayloadByBitrixId[$bitrixId] = $this->normalizeEmails($item['EMAIL'] ?? []);
                    $currentBatchBitrixIds[] = $bitrixId;
                    $successful++;
                    $this->line('Contact '.$bitrixId.' OK');
                } catch (\Throwable) {
                    $failedId = $item['ID'] ?? 'unknown';
                    $failedContactIds[] = $failedId;
                    $this->warn('Contact '.$failedId.' FAIL');
                }
            }

            if ($contactsPayload !== []) {
                DB::transaction(function () use ($contactsPayload, $currentBatchBitrixIds, $phonePayloadByBitrixId, $emailPayloadByBitrixId, $now): void {
                    Contact::query()->upsert(
                        $contactsPayload,
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

                    $this->insertContactPhones($contactIdByBitrixId, $phonePayloadByBitrixId, $now);
                    $this->insertContactEmails($contactIdByBitrixId, $emailPayloadByBitrixId, $now);
                });

                $this->info(sprintf(
                    'Batch synced. Total processed: %d, successful: %d, failed: %d.',
                    $total,
                    $successful,
                    count($failedContactIds)
                ));
            }

            $next = data_get($response->json(), 'next');
            if (! is_numeric($next)) {
                break;
            }

            $start = (int) $next;
        }

        return [
            'total' => $total,
            'successful' => $successful,
            'failed' => count($failedContactIds),
            'failed_contact_ids' => $failedContactIds,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function resolveContactTypeMap(): array
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
     * @param  array<string, int>  $contactTypeMap
     */
    private function resolveDefaultContactTypeId(array $contactTypeMap): ?int
    {
        return $contactTypeMap['not_selected'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $contactTypeMap
     * @return array<string, mixed>
     */
    private function normalizeContact(array $item, Carbon $syncedAt, array $contactTypeMap, ?int $defaultContactTypeId): array
    {
        $bitrixId = (int) ($item['ID'] ?? 0);

        if ($bitrixId <= 0) {
            throw new \RuntimeException('Invalid Bitrix contact id');
        }

        $typeKey = $this->normalizeTypeKey((string) ($item['TYPE_ID'] ?? ''));

        return [
            'id' => (string) Str::uuid(),
            'bitrix_id' => $bitrixId,
            'first_name' => $this->toNullableString($item['NAME'] ?? null),
            'last_name' => $this->toNullableString($item['LAST_NAME'] ?? null),
            'contact_type_id' => $contactTypeMap[$typeKey] ?? $defaultContactTypeId,
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
    private function normalizePhones(mixed $phones): array
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
    private function normalizeEmails(mixed $emails): array
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
    private function insertContactPhones(Collection $contactIdByBitrixId, array $phonePayloadByBitrixId, Carbon $now): void
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
    private function insertContactEmails(Collection $contactIdByBitrixId, array $emailPayloadByBitrixId, Carbon $now): void
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

    private function buildUrl(string $method): string
    {
        return rtrim((string) config('services.bitrix.webhook'), '/').'/'.$method;
    }
}
