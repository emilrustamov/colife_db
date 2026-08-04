<?php

namespace App\Services\Disk;

use App\Services\BitrixWebhook;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FileResolver
{
    /**
     * @var list<int>
     */
    private const FALLBACK_ENTITY_TYPE_IDS = [2, 1, 3, 4];

    public function __construct(
        private readonly BitrixWebhook $webhookClient,
    ) {}

    /**
     * Load CRM item file catalog keyed by file id.
     *
     * @return array<int, array{id: int, url: string, field_name: string}>
     */
    public function loadFileCatalog(int $entityTypeId, int $entityId): array
    {
        $catalog = [];

        $response = $this->webhookClient->callRaw('crm.item.get', [
            'entityTypeId' => $entityTypeId,
            'id' => $entityId,
        ], $this->webhookClient->diskWebhook());

        if (! isset($response['error'])) {
            $item = data_get($response, 'result.item');
            if (is_array($item)) {
                $catalog = $this->extractFilesFromFields($item);
            }
        }

        $classicMethod = match ($entityTypeId) {
            2 => 'crm.deal.get',
            1 => 'crm.lead.get',
            3 => 'crm.contact.get',
            4 => 'crm.company.get',
            default => null,
        };

        if ($classicMethod !== null) {
            $classic = $this->webhookClient->callRaw($classicMethod, [
                'id' => $entityId,
            ], $this->webhookClient->diskWebhook());

            if (! isset($classic['error']) && is_array($classic['result'] ?? null)) {
                foreach ($this->extractFilesFromFields($classic['result']) as $fileId => $meta) {
                    if (! isset($catalog[$fileId])) {
                        $catalog[$fileId] = $meta;
                    }
                }
            }
        }

        if ($catalog === [] && isset($response['error'])) {
            throw new RuntimeException(
                'CRM item get failed: '.((string) ($response['error_description'] ?? $response['error']))
            );
        }

        return $catalog;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<int, array{id: int, url: string, field_name: string}>
     */
    private function extractFilesFromFields(array $fields): array
    {
        $catalog = [];

        foreach ($fields as $fieldName => $value) {
            if (! is_array($value)) {
                continue;
            }

            foreach ($this->normalizeFileEntries($value) as $entry) {
                $fileId = (int) ($entry['id'] ?? 0);
                $url = trim((string) ($entry['urlMachine'] ?? $entry['url'] ?? $entry['downloadUrl'] ?? ''));
                if ($fileId <= 0 || $url === '') {
                    continue;
                }

                if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                    $domain = rtrim((string) config('services.bitrix.portal_domain', ''), '/');
                    if ($domain !== '') {
                        $url = 'https://'.$domain.$url;
                    }
                }

                $catalog[$fileId] = [
                    'id' => $fileId,
                    'url' => $url,
                    'field_name' => (string) $fieldName,
                ];
            }
        }

        return $catalog;
    }

    /**
     * Bitrix returns one file as an object and many files as a list.
     *
     * @param  array<mixed>  $value
     * @return list<array<string, mixed>>
     */
    private function normalizeFileEntries(array $value): array
    {
        if ($value === []) {
            return [];
        }

        if (array_key_exists('id', $value) || array_key_exists('urlMachine', $value) || array_key_exists('downloadUrl', $value)) {
            return [$value];
        }

        $entries = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Resolve CRM entity type/id from list values and element name.
     *
     * @return array{entity_type_id: int, entity_id: int}|null
     */
    public function resolveEntity(?int $crmId, ?string $elementName = null): ?array
    {
        $fromName = $this->parseEntityFromName($elementName);
        if ($fromName !== null) {
            if ($crmId === null || $crmId === $fromName['entity_id']) {
                return $fromName;
            }
        }

        if ($crmId === null || $crmId <= 0) {
            return null;
        }

        if ($fromName !== null && $fromName['entity_id'] === $crmId) {
            return $fromName;
        }

        foreach (self::FALLBACK_ENTITY_TYPE_IDS as $entityTypeId) {
            $response = $this->webhookClient->callRaw('crm.item.get', [
                'entityTypeId' => $entityTypeId,
                'id' => $crmId,
            ], $this->webhookClient->diskWebhook());

            if (! isset($response['error']) && is_array(data_get($response, 'result.item'))) {
                return [
                    'entity_type_id' => $entityTypeId,
                    'entity_id' => $crmId,
                ];
            }
        }

        return null;
    }

    /**
     * Download CRM file by urlMachine and return body metadata.
     *
     * @return array{contents: string, mime: ?string, filename: ?string, size: int}
     */
    public function download(string $url): array
    {
        $response = Http::timeout(180)->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('CRM file download failed: HTTP '.$response->status());
        }

        $mime = $response->header('Content-Type');
        $mime = is_string($mime) ? trim(explode(';', $mime)[0]) : null;
        $contents = $response->body();

        if ($mime === 'application/json' || str_starts_with(ltrim($contents), '{')) {
            throw new RuntimeException('CRM file download returned JSON instead of file contents.');
        }

        $filename = $this->filenameFromDisposition($response->header('Content-Disposition'));

        return [
            'contents' => $contents,
            'mime' => $mime,
            'filename' => $filename,
            'size' => strlen($contents),
        ];
    }

    /**
     * @return array{entity_type_id: int, entity_id: int}|null
     */
    private function parseEntityFromName(?string $elementName): ?array
    {
        if ($elementName === null || $elementName === '') {
            return null;
        }

        if (preg_match('/\b(D|L|C|CO)_(\d+)\b/u', $elementName, $matches) !== 1) {
            return null;
        }

        $prefix = $matches[1];
        $entityId = (int) $matches[2];
        $map = [
            'D' => 2,
            'L' => 1,
            'C' => 3,
            'CO' => 4,
        ];

        if (! isset($map[$prefix]) || $entityId <= 0) {
            return null;
        }

        return [
            'entity_type_id' => $map[$prefix],
            'entity_id' => $entityId,
        ];
    }

    private function filenameFromDisposition(mixed $header): ?string
    {
        if (! is_string($header) || $header === '') {
            return null;
        }

        if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $header, $matches) === 1) {
            return rawurldecode(trim($matches[1], " \t\"'"));
        }

        if (preg_match('/filename="?([^";]+)"?/i', $header, $matches) === 1) {
            return trim($matches[1], " \t\"'");
        }

        return null;
    }
}
