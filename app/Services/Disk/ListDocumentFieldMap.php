<?php

namespace App\Services\Disk;

use App\Services\BitrixWebhookClient;

class ListDocumentFieldMap
{
    private const IBLOCK_TYPE_ID = 'lists';

    public function __construct(
        private readonly BitrixWebhookClient $webhookClient,
    ) {}

    /**
     * Discover folder, CRM entity and document file fields from list metadata.
     *
     * @return array{
     *     folder_field_id: ?string,
     *     folder_code: ?string,
     *     folder_url_field_id: ?string,
     *     folder_url_code: ?string,
     *     direction_field_id: ?string,
     *     direction_code: ?string,
     *     direction_values: array<string, string>,
     *     crm_field_id: ?string,
     *     crm_code: ?string,
     *     file_fields: array<string, array{field_id: string, code: string, name: string, slug: string}>
     * }
     */
    public function load(int $iblockId): array
    {
        $response = $this->webhookClient->call('lists.field.get', [
            'IBLOCK_TYPE_ID' => self::IBLOCK_TYPE_ID,
            'IBLOCK_ID' => $iblockId,
        ], $this->webhookClient->diskWebhook());

        $fields = data_get($response, 'result', []);
        if (! is_array($fields)) {
            return [
                'folder_field_id' => null,
                'folder_code' => null,
                'folder_url_field_id' => null,
                'folder_url_code' => null,
                'direction_field_id' => null,
                'direction_code' => null,
                'direction_values' => [],
                'crm_field_id' => null,
                'crm_code' => null,
                'file_fields' => [],
            ];
        }

        $folderFieldId = null;
        $folderCode = null;
        $folderUrlFieldId = null;
        $folderUrlCode = null;
        $directionFieldId = null;
        $directionCode = null;
        $directionValues = [];
        $crmFieldId = null;
        $crmCode = null;
        $fileFields = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = trim((string) ($field['NAME'] ?? ''));
            $code = trim((string) ($field['CODE'] ?? ''));
            $fieldId = trim((string) ($field['FIELD_ID'] ?? ''));
            if ($name === '' || $fieldId === '') {
                continue;
            }

            if ($this->isFolderField($name)) {
                $folderFieldId = $fieldId;
                $folderCode = $code !== '' ? $code : $fieldId;

                continue;
            }

            if ($this->isFolderUrlField($name, $code)) {
                $folderUrlFieldId = $fieldId;
                $folderUrlCode = $code !== '' ? $code : $fieldId;

                continue;
            }

            if ($this->isDirectionField($name, $code)) {
                $directionFieldId = $fieldId;
                $directionCode = $code !== '' ? $code : $fieldId;
                $displayValues = $field['DISPLAY_VALUES_FORM'] ?? [];
                if (is_array($displayValues)) {
                    foreach ($displayValues as $enumId => $label) {
                        $directionValues[(string) $enumId] = trim((string) $label);
                    }
                }

                continue;
            }

            if ($this->isCrmEntityField($name, $code)) {
                $crmFieldId = $fieldId;
                $crmCode = $code !== '' ? $code : $fieldId;

                continue;
            }

            if ($this->isFileField($name, $code)) {
                $slugSource = $code !== '' ? $code : $fieldId;
                $fileFields[$fieldId] = [
                    'field_id' => $fieldId,
                    'code' => $code !== '' ? $code : $fieldId,
                    'name' => $name,
                    'slug' => $this->slugify($slugSource),
                ];
            }
        }

        return [
            'folder_field_id' => $folderFieldId,
            'folder_code' => $folderCode,
            'folder_url_field_id' => $folderUrlFieldId,
            'folder_url_code' => $folderUrlCode,
            'direction_field_id' => $directionFieldId,
            'direction_code' => $directionCode,
            'direction_values' => $directionValues,
            'crm_field_id' => $crmFieldId,
            'crm_code' => $crmCode,
            'file_fields' => $fileFields,
        ];
    }

    /**
     * Read raw property value for a FIELD_ID from list element.
     *
     * @param  array<string, mixed>  $item
     */
    public function propertyRaw(array $item, string $fieldId, ?string $code = null): mixed
    {
        $candidates = [$fieldId];
        if (is_string($code) && $code !== '') {
            $candidates[] = 'PROPERTY_'.$code;
            $candidates[] = $code;
        }

        foreach ($candidates as $key) {
            if (array_key_exists($key, $item)) {
                return $item[$key];
            }
        }

        return null;
    }

    /**
     * Extract all numeric file IDs from a list property value.
     *
     * @return list<int>
     */
    public function parseFileIds(mixed $value): array
    {
        $ids = [];
        foreach ($this->flattenValues($value) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }

            if (is_numeric($piece)) {
                $id = (int) $piece;
                if ($id > 0) {
                    $ids[$id] = $id;
                }

                continue;
            }

            if (preg_match_all('/\d+/', $piece, $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $id = (int) $match;
                    if ($id > 0) {
                        $ids[$id] = $id;
                    }
                }
            }
        }

        return array_values($ids);
    }

    /**
     * Extract a single folder ID from property value.
     */
    public function parseFolderId(mixed $value): ?int
    {
        $ids = $this->parseFileIds($value);

        return $ids[0] ?? null;
    }

    /**
     * Extract CRM entity numeric id from property value.
     */
    public function parseCrmId(mixed $value): ?int
    {
        return $this->parseFolderId($value);
    }

    /**
     * Extract a single string URL from property value.
     */
    public function parseUrl(mixed $value): ?string
    {
        foreach ($this->flattenValues($value) as $piece) {
            $piece = trim($piece);
            if ($piece !== '' && (str_starts_with($piece, 'http://') || str_starts_with($piece, 'https://'))) {
                return $piece;
            }
        }

        return null;
    }

    /**
     * Resolve funnel/direction label from list enum property.
     *
     * @param  array<string, string>  $directionValues
     */
    public function resolveDirectionLabel(mixed $value, array $directionValues): ?string
    {
        foreach ($this->flattenValues($value) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }

            if (isset($directionValues[$piece]) && $directionValues[$piece] !== '') {
                return $directionValues[$piece];
            }

            if (! is_numeric($piece) && ! isset($directionValues[$piece])) {
                return $piece;
            }
        }

        return null;
    }

    /**
     * Split direction label like "Invest. Sellers" into path segments.
     *
     * @return list<string>
     */
    public function directionPathSegments(?string $label): array
    {
        if ($label === null || trim($label) === '') {
            return [];
        }

        $parts = preg_split('/\s*\.\s*/u', trim($label)) ?: [];
        $segments = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $segments[] = $part;
            }
        }

        return $segments;
    }

    /**
     * Parse Bitrix Disk docs URL path segments after /docs/path/.
     *
     * @return list<string>
     */
    public function pathSegmentsFromDocsUrl(?string $url): array
    {
        if ($url === null || $url === '') {
            return [];
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return [];
        }

        if (preg_match('#/docs/path/(.+)$#u', $path, $matches) !== 1) {
            return [];
        }

        $raw = rawurldecode($matches[1]);
        $parts = explode('/', $raw);
        $segments = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && $part !== '.' && $part !== '..') {
                $segments[] = $part;
            }
        }

        return $segments;
    }

    /**
     * Build nested folder path: direction segments + leaf folder name.
     *
     * @param  list<string>  $prefixSegments
     */
    public function buildFolderPath(array $prefixSegments, string $leafName): string
    {
        $segments = [];
        foreach ($prefixSegments as $segment) {
            $segment = trim($segment);
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        $leafName = trim($leafName);
        if ($leafName !== '') {
            $leaf = $segments === [] ? null : $segments[array_key_last($segments)];
            if ($leaf === null || mb_strtolower($leaf) !== mb_strtolower($leafName)) {
                $segments[] = $leafName;
            }
        }

        return implode('/', $segments);
    }

    private function isFolderField(string $name): bool
    {
        $lower = mb_strtolower($name);

        return str_contains($lower, 'папки на диске')
            || str_contains($lower, 'id папки');
    }

    private function isFolderUrlField(string $name, string $code): bool
    {
        $lower = mb_strtolower($name);
        $codeUpper = mb_strtoupper($code);

        return str_contains($lower, 'ссылка на папку')
            || $codeUpper === 'SSYLKA_NA_PAPKU'
            || $codeUpper === 'FOLDER_URL';
    }

    private function isDirectionField(string $name, string $code): bool
    {
        $lower = mb_strtolower($name);
        $codeUpper = mb_strtoupper($code);

        return str_contains($lower, 'воронка')
            || str_contains($lower, 'направление')
            || $codeUpper === 'VORONKA_NAPRAVLENIE';
    }

    private function isCrmEntityField(string $name, string $code): bool
    {
        $lower = mb_strtolower($name);
        $codeUpper = mb_strtoupper($code);

        return str_contains($lower, 'crm сущност')
            || $codeUpper === 'ID_CRM'
            || $codeUpper === 'CRM';
    }

    private function isFileField(string $name, string $code = ''): bool
    {
        $lower = mb_strtolower($name);
        $codeUpper = mb_strtoupper($code);

        if (
            str_contains($lower, 'папки на диске')
            || str_contains($lower, 'ссылка на папку')
            || str_contains($lower, 'crm сущност')
            || str_contains($lower, 'воронка')
            || str_contains($lower, 'направление')
        ) {
            return false;
        }

        if (str_contains($lower, '(внутри crm)')) {
            return true;
        }

        if (str_contains($lower, 'property document')) {
            return true;
        }

        if ($codeUpper === 'PROPERTY_DOCUMENT_NOT_TITLE_DEED') {
            return true;
        }

        if (preg_match('/^ID_/u', $codeUpper) === 1) {
            return true;
        }

        return (bool) preg_match('/^id[\s_]/iu', $name);
    }

    /**
     * @return list<string>
     */
    private function flattenValues(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            if ($value === []) {
                return [];
            }

            $out = [];
            foreach ($value as $item) {
                foreach ($this->flattenValues($item) as $nested) {
                    $out[] = $nested;
                }
            }

            return $out;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return [];
        }

        if (str_contains($string, ',') || str_contains($string, ';')) {
            return preg_split('/[,;]+/', $string) ?: [$string];
        }

        return [$string];
    }

    private function slugify(string $value): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $value) ?? $value;
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'field';
    }
}
