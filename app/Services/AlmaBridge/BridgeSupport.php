<?php

namespace App\Services\AlmaBridge;

use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Facades\Log;

class BridgeSupport
{
    /**
     * Whether Alma Bridge may call Alma / Bitrix write APIs.
     */
    public static function enabled(): bool
    {
        return (bool) config('alma_bridge.enabled', false);
    }

    /**
     * @return \Psr\Log\LoggerInterface
     */
    public static function log()
    {
        return Log::channel('alma_bridge');
    }

    /**
     * Format Bitrix date value for Alma ISO UTC.
     */
    public static function formatDateForAlma(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_array($value)) {
            $value = $value[0] ?? '';
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        try {
            return Carbon::parse($raw)->utc()->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Map Bitrix contact to Alma client payload.
     *
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    public static function contactToClient(array $contact, ?string $emailField, string $projectName): array
    {
        $email = '';
        if ($emailField) {
            $raw = ProjectMapping::itemValue($contact, $emailField);
            if (is_array($raw)) {
                $email = (string) ($raw[0]['VALUE'] ?? $raw['VALUE'] ?? '');
            } else {
                $email = (string) $raw;
            }
        }
        if ($email === '') {
            $emails = $contact['EMAIL'] ?? [];
            if (is_array($emails) && isset($emails[0]['VALUE'])) {
                $email = (string) $emails[0]['VALUE'];
            }
        }

        $phone = '';
        $phones = $contact['PHONE'] ?? [];
        if (is_array($phones) && isset($phones[0]['VALUE'])) {
            $phone = (string) $phones[0]['VALUE'];
        }

        $nationalityField = ProjectMapping::getTenantField('nationality', $projectName)
            ?: ProjectMapping::getLandlordField('nationality', $projectName);
        $nationality = $nationalityField
            ? ProjectMapping::mapNationality(ProjectMapping::itemValue($contact, $nationalityField), $projectName)
            : null;

        return [
            'id' => (string) ($contact['ID'] ?? $contact['id'] ?? ''),
            'first_name' => (string) ($contact['NAME'] ?? ''),
            'last_name' => (string) ($contact['LAST_NAME'] ?? ''),
            'email' => mb_strtolower(trim($email)),
            'phone' => $phone,
            'country' => $nationality ?? 4,
        ];
    }

    /**
     * Detect ONUPDATE echo after our timeline comment (create or error).
     * Comments contain "Alma sync"; author must be the Bitrix REST integration user.
     * Prevents: create/error comment → item update webhook → sync → comment → loop.
     *
     * @param  array<string, mixed>  $entityData
     */
    public static function isWebhookEcho(
        BitrixWebhookClient $bitrix,
        int $entityTypeId,
        int|string $entityId,
        array $entityData,
        ?int $integrationUserId
    ): bool {
        if ($integrationUserId === null) {
            return false;
        }

        $list = $bitrix->call('crm.timeline.comment.list', [
            'filter' => [
                'ENTITY_ID' => (int) $entityId,
                'ENTITY_TYPE' => 'dynamic_'.$entityTypeId,
            ],
            'order' => ['ID' => 'DESC'],
        ]);
        $items = $list['result'] ?? [];
        if (! is_array($items) || $items === []) {
            return false;
        }

        $latest = $items[0];
        $text = (string) ($latest['COMMENT'] ?? '');
        $authorId = (int) ($latest['AUTHOR_ID'] ?? 0);
        if ($authorId !== $integrationUserId || ! str_contains($text, 'Alma sync')) {
            return false;
        }

        $commentTs = strtotime((string) ($latest['CREATED'] ?? ''));
        $updatedTs = strtotime((string) ($entityData['updatedTime'] ?? $entityData['UPDATED_TIME'] ?? ''));
        if ($commentTs === false) {
            return false;
        }
        if ($updatedTs === false) {
            return true;
        }

        return $updatedTs <= $commentTs;
    }

    public static function addCrmTimelineComment(
        BitrixWebhookClient $bitrix,
        int $entityTypeId,
        int|string $entityId,
        string $comment
    ): void {
        $bitrix->call('crm.timeline.comment.add', [
            'fields' => [
                'ENTITY_ID' => (int) $entityId,
                'ENTITY_TYPE' => 'dynamic_'.$entityTypeId,
                'COMMENT' => $comment,
            ],
        ]);
    }

    public static function formatErrorComment(string $rawMessage): string
    {
        return self::formatSyncErrorComment($rawMessage);
    }

    public static function formatSyncErrorComment(
        string $rawMessage,
        int|string|null $almaUnitId = null,
        mixed $startDate = null,
        mixed $endDate = null
    ): string {
        $detail = trim($rawMessage);
        $detail = preg_replace('/^API request failed \(\d+\):\s*/', '', $detail) ?? $detail;
        $detail = preg_replace('/^Alma API request failed \(\d+\):\s*/', '', $detail) ?? $detail;
        $detail = preg_replace('/^Bitrix24 API error \(HTTP \d+\):\s*/', '', $detail) ?? $detail;
        $detail = preg_replace('/^API error:\s*/', '', $detail) ?? $detail;
        $detail = preg_replace('/^Validation error:\s*/', '', $detail) ?? $detail;

        $decoded = json_decode($detail, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($decoded) && isset($decoded['error_description']) && (string) $decoded['error_description'] !== '') {
                $detail = (string) $decoded['error_description'];
            } elseif (is_array($decoded)) {
                $detail = self::flattenErrorPayload($decoded);
            }
        }

        $detail = trim((string) $detail);
        if ($detail === '') {
            $detail = trim($rawMessage);
        }

        if (stripos($detail, 'intersection') !== false) {
            $comment = "[color=#ff0000]Alma sync error:[/color]\n[b]Пересечение дат[/b]";
            $link = self::formatUnitLink($almaUnitId);
            if ($link !== '') {
                $comment .= "\n".$link;
            }

            return $comment;
        }

        $detail = self::clarifyErrorDetail($detail, $startDate, $endDate);

        return "[color=#ff0000]Alma sync error:[/color]\n[b]".$detail.'[/b]';
    }

    public static function formatApartmentCreatedComment(int|string $almaUnitId): string
    {
        $lines = [
            '[color=#008000]Alma sync:[/color]',
            '[b]Апартамент успешно создан[/b]',
        ];
        $link = self::formatUnitLink($almaUnitId);
        if ($link !== '') {
            $lines[] = $link;
        }

        return implode("\n", $lines);
    }

    public static function formatContractCreatedComment(int|string $almaClientId): string
    {
        $base = rtrim((string) config('services.alma.web_url', 'https://colife.alma-app.com'), '/');
        $url = $base.'/clients/'.rawurlencode((string) $almaClientId);

        return "[color=#008000]Alma sync:[/color]\n[b]Контракт успешно создан[/b]\n[url=".$url.']Открыть клиента в Alma[/url]';
    }

    public static function formatUnitLink(int|string|null $almaUnitId): string
    {
        if ($almaUnitId === null || $almaUnitId === '') {
            return '';
        }
        $base = rtrim((string) config('services.alma.web_url', 'https://colife.alma-app.com'), '/');
        $url = $base.'/units/'.rawurlencode((string) $almaUnitId);

        return '[url='.$url.']Открыть апартамент в Alma[/url]';
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    public static function isAlmaContractArchived(array $contract): bool
    {
        $status = (string) ($contract['unit_usage']['status'] ?? '');
        if ($status === 'archived' || $status === 'canceled') {
            return true;
        }

        return ! empty($contract['is_archived']);
    }

    private static function clarifyErrorDetail(string $detail, mixed $startDate = null, mixed $endDate = null): string
    {
        if (stripos($detail, 'Client with received email already exists') !== false) {
            return 'Дубль клиента: email уже существует в Alma';
        }
        if (stripos($detail, 'Invalid rental period') !== false) {
            return self::formatInvalidRentalPeriodDetail($startDate, $endDate);
        }

        return $detail;
    }

    private static function formatInvalidRentalPeriodDetail(mixed $startDate = null, mixed $endDate = null): string
    {
        $startLabel = self::formatDateForComment($startDate);
        $endLabel = self::formatDateForComment($endDate);
        if ($startLabel === '' && $endLabel === '') {
            return 'Некорректный период дат (дата окончания раньше начала или период недопустим)';
        }

        $startDt = self::parseCommentDate($startDate);
        $endDt = self::parseCommentDate($endDate);
        if ($startDt instanceof DateTime && $endDt instanceof DateTime && $endDt < $startDt) {
            return 'Дата выселения '.$endLabel.' раньше, чем дата заселения '.$startLabel;
        }

        return 'Некорректный период дат: заселение '.($startLabel !== '' ? $startLabel : '—')
            .', выселение '.($endLabel !== '' ? $endLabel : '—');
    }

    private static function formatDateForComment(mixed $date): string
    {
        $dt = self::parseCommentDate($date);
        if ($dt instanceof DateTime) {
            return $dt->format('d.m.Y');
        }

        return trim((string) $date);
    }

    private static function parseCommentDate(mixed $date): ?DateTime
    {
        $raw = trim((string) $date);
        if ($raw === '') {
            return null;
        }
        $dt = date_create(substr($raw, 0, 10)) ?: date_create($raw);
        if (! $dt instanceof DateTime) {
            return null;
        }
        $dt->setTime(0, 0, 0);

        return $dt;
    }

    /**
     * @param  array<mixed>  $payload
     */
    private static function flattenErrorPayload(array $payload): string
    {
        $parts = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $parts[] = $key.': '.self::flattenErrorPayload($value);
            } else {
                $parts[] = $key.': '.(string) $value;
            }
        }

        return implode('; ', $parts);
    }
}
