<?php

namespace App\Services\AlmaBridge;

use Carbon\Carbon;
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
     * Detect webhook echo after Alma error comment on timeline.
     *
     * @param  array<string, mixed>  $contractData
     */
    public static function isWebhookEcho(
        BitrixWebhookClient $bitrix,
        int $entityTypeId,
        int|string $entityId,
        array $contractData,
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
        $updatedTs = strtotime((string) ($contractData['updatedTime'] ?? $contractData['UPDATED_TIME'] ?? ''));
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
        return 'Alma sync error: '.$rawMessage;
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
}
