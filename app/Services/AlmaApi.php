<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class AlmaApi
{
    /**
     * Whether Alma rejected update because the usage/contract is archived (HTTP 400).
     */
    public static function isArchivedConflict(Throwable $e): bool
    {
        if (! $e instanceof RuntimeException || $e->getCode() !== 400) {
            return false;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, '"is_archived"')
            || str_contains($message, 'archived')
            || str_contains($message, 'archive usage');
    }

    /**
     * Fetch all Alma clients.
     *
     * @return list<array<string, mixed>>
     */
    public function listClients(): array
    {
        $response = $this->client()
            ->get('users/clients/')
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new RuntimeException('Alma clients list response is invalid.');
        }

        return array_values(array_filter($response, 'is_array'));
    }

    /**
     * Push landlord property finance month batch.
     *
     * @param  list<array<string, mixed>>  $payload
     * @return array<string, mixed>
     */
    public function pushLandlordPropertyFinance(array $payload): array
    {
        $apiKey = trim((string) config('services.landlord_finance.api_key'));
        if ($apiKey === '') {
            $apiKey = trim((string) config('services.alma.api_key'));
        }

        $apiUrl = rtrim(trim((string) config('services.alma.api_url')), '/').'/';
        if ($apiUrl === '/' || $apiKey === '') {
            throw new RuntimeException('Alma API URL or landlord finance API key is not configured.');
        }

        $response = Http::baseUrl($apiUrl)
            ->withHeaders(['Api-Key' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.alma.timeout', 120))
            ->post('realty_finance/landlord_property_finance/', $payload);

        if ($response->failed()) {
            $body = $response->json() ?? $response->body();
            $detail = is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : (string) $body;
            throw new RuntimeException(
                'Alma landlord finance failed ('.$response->status().'): '.$detail,
                $response->status()
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Alma landlord finance returned invalid JSON.');
        }

        return $json;
    }

    /**
     * Patch Alma client fields by Alma internal id.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateClient(int $almaId, array $payload): array
    {
        return $this->json('patch', 'users/clients/'.$almaId.'/', $payload);
    }

    /**
     * Create Alma client.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createClient(array $payload): array
    {
        return $this->json('post', 'users/clients/', $payload);
    }

    /**
     * Get client by Bitrix external id.
     *
     * @return array<string, mixed>|null
     */
    public function clientByExternalId(string $externalId): ?array
    {
        return $this->byExternalId('users/clients/external_id/'.$externalId.'/');
    }

    /**
     * Create tenant contract (usage).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createTenantContract(array $payload): array
    {
        return $this->json('post', 'realty/contracts/tenant_contracts/', $payload);
    }

    /**
     * Update tenant contract by Alma id.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateTenantContract(int|string $almaId, array $payload): array
    {
        return $this->json('patch', 'realty/contracts/tenant_contracts/'.$almaId.'/', $payload);
    }

    /**
     * Get tenant contract by Bitrix external id.
     *
     * @return array<string, mixed>|null
     */
    public function tenantByExternalId(string $externalId): ?array
    {
        return $this->byExternalId('realty/contracts/tenant_contracts/external_id/'.$externalId.'/');
    }

    /**
     * Archive tenant contract by external id.
     *
     * @return array<string, mixed>
     */
    public function archiveTenant(string $externalId, bool $archived = true): array
    {
        return $this->json(
            'patch',
            'realty/contracts/tenant_contracts/external_id/'.$externalId.'/archive/',
            ['is_archived' => $archived]
        );
    }

    /**
     * Create landlord (owner) contract.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createLandlordContract(array $payload): array
    {
        return $this->json('post', 'realty/contracts/owner_contracts/', $payload);
    }

    /**
     * Update landlord contract.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateLandlordContract(int|string $almaId, array $payload): array
    {
        return $this->json('patch', 'realty/contracts/owner_contracts/'.$almaId.'/', $payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function landlordByExternalId(string $externalId): ?array
    {
        return $this->byExternalId('realty/contracts/owner_contracts/external_id/'.$externalId.'/');
    }

    /**
     * Create or update unit (apartment).
     *
     * @param  array<string, mixed>  $payload
     * @return array{method: string, response: array<string, mixed>}
     */
    public function upsertUnit(string $externalId, array $payload): array
    {
        if (isset($payload['building']) && is_array($payload['building'])) {
            $payload['building'] = $payload['building']['id'] ?? null;
        }

        $existing = $this->unitByExternalId($externalId);
        if ($existing !== null && isset($existing['id'])) {
            if (! empty($existing['is_archived'])) {
                $this->setUnitArchived($externalId, false);
            }
            if (! isset($payload['name']) || (string) $payload['name'] === '') {
                $payload['name'] = 'Apartment '.$externalId;
            }

            return [
                'method' => 'PATCH',
                'response' => $this->json('patch', 'realty/units/'.$existing['id'].'/', $payload),
            ];
        }

        $payload['external_id'] = $externalId;

        return [
            'method' => 'POST',
            'response' => $this->json('post', 'realty/units/', $payload),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function unitByExternalId(string $externalId): ?array
    {
        return $this->byExternalId('realty/units/external_id/'.$externalId.'/');
    }

    /**
     * List Alma units (short rows).
     *
     * @return list<array<string, mixed>>
     */
    public function listUnits(): array
    {
        $response = $this->client()->get('realty/units/')->throw()->json();
        if (! is_array($response)) {
            throw new RuntimeException('Alma units list response is invalid.');
        }

        return array_values(array_filter($response, 'is_array'));
    }

    /**
     * Get Alma client by internal id.
     *
     * @return array<string, mixed>|null
     */
    public function clientById(int $almaClientId): ?array
    {
        if ($almaClientId <= 0) {
            return null;
        }

        $response = $this->client()->get('users/clients/'.$almaClientId.'/');
        if ($response->status() === 404) {
            return null;
        }
        $response->throw();
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * List owner contracts (short rows).
     *
     * @return list<array<string, mixed>>
     */
    public function listOwnerContracts(): array
    {
        $response = $this->client()->get('realty/contracts/owner_contracts/')->throw()->json();
        if (! is_array($response)) {
            throw new RuntimeException('Alma owner contracts list response is invalid.');
        }

        return array_values(array_filter($response, 'is_array'));
    }

    /**
     * Get owner contract by Alma id.
     *
     * @return array<string, mixed>|null
     */
    public function ownerContractById(int $contractId): ?array
    {
        if ($contractId <= 0) {
            return null;
        }

        $response = $this->client()->get('realty/contracts/owner_contracts/'.$contractId.'/');
        if ($response->status() === 404) {
            return null;
        }
        $response->throw();
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function setUnitArchived(string $externalId, bool $archived): array
    {
        return $this->json(
            'patch',
            'realty/units/external_id/'.$externalId.'/archive/',
            ['is_archived' => $archived]
        );
    }

    /**
     * Create room.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createRoom(array $payload): array
    {
        return $this->json('post', 'realty/rooms/', $payload);
    }

    /**
     * Update room.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateRoom(int|string $almaId, array $payload): array
    {
        return $this->json('patch', 'realty/rooms/'.$almaId.'/', $payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function roomByExternalId(string $externalId): ?array
    {
        return $this->byExternalId('realty/rooms/external_id/'.$externalId.'/');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{method: string, response: array<string, mixed>}
     */
    public function upsertRoom(string $externalId, array $payload): array
    {
        $existing = $this->roomByExternalId($externalId);
        if ($existing !== null && isset($existing['id'])) {
            return [
                'method' => 'PATCH',
                'response' => $this->updateRoom($existing['id'], $this->roomPatchPayload($payload)),
            ];
        }

        $payload['external_id'] = $externalId;

        try {
            return [
                'method' => 'POST',
                'response' => $this->createRoom($payload),
            ];
        } catch (RuntimeException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'already exists')) {
                throw $e;
            }
            $conflictId = $this->findRoomIdByNameConflict($payload);
            if ($conflictId === null) {
                throw $e;
            }

            return [
                'method' => 'PATCH',
                'response' => $this->updateRoom($conflictId, $this->roomPatchPayload($payload, true)),
            ];
        }
    }

    /**
     * Create building by name lookup-or-create pattern used by bridge.
     *
     * @return array<string, mixed>
     */
    public function getOrCreateBuilding(string $buildingName, ?int $projectId = null): array
    {
        $list = $this->client()->get('realty/buildings/', ['search' => $buildingName])->throw()->json();
        if (is_array($list)) {
            foreach ($list as $row) {
                if (is_array($row) && strcasecmp(trim((string) ($row['name'] ?? '')), trim($buildingName)) === 0) {
                    return $row;
                }
            }
        }

        $payload = ['name' => $buildingName];
        if ($projectId !== null && $projectId > 0) {
            $payload['project'] = $projectId;
        }

        return $this->json('post', 'realty/buildings/', $payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildingById(int|string $buildingId): ?array
    {
        $response = $this->client()->get('realty/buildings/'.$buildingId.'/');
        if ($response->status() === 404) {
            return null;
        }
        $response->throw();
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function setBuildingArchived(int|string $buildingId, bool $archived): array
    {
        return $this->json(
            'patch',
            'realty/buildings/'.$buildingId.'/archive/',
            ['is_archived' => $archived]
        );
    }

    /**
     * Archive building when it has zero units left.
     */
    public function archiveBuildingIfEmpty(int|string $buildingId): void
    {
        $building = $this->buildingById($buildingId);
        if ($building !== null && (int) ($building['number_of_units'] ?? -1) === 0) {
            $this->setBuildingArchived($buildingId, true);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function roomPatchPayload(array $payload, bool $bindExternalId = false): array
    {
        if (isset($payload['parent_unit']) && is_array($payload['parent_unit'])) {
            $payload['parent_unit'] = $payload['parent_unit']['id'] ?? null;
        }
        if (! $bindExternalId) {
            unset($payload['external_id']);
        }
        unset($payload['property_type']);
        if (empty($payload['name'])) {
            $payload['name'] = 'Room '.($payload['external_id'] ?? '');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findRoomIdByNameConflict(array $data): int|string|null
    {
        $targetName = trim((string) ($data['name'] ?? ''));
        if ($targetName === '') {
            return null;
        }

        $parentId = $data['parent_unit'] ?? null;
        if (is_array($parentId)) {
            $parentId = $parentId['id'] ?? null;
        }

        $list = $this->client()->get('realty/rooms/')->throw()->json();
        if (! is_array($list)) {
            return null;
        }

        $fallbackId = null;
        foreach ($list as $room) {
            if (! is_array($room) || trim((string) ($room['name'] ?? '')) !== $targetName) {
                continue;
            }
            $roomId = $room['id'] ?? null;
            if ($roomId === null) {
                continue;
            }
            $externalId = (string) ($room['external_id'] ?? '');
            if ($externalId !== '' && $externalId !== (string) ($data['external_id'] ?? '')) {
                continue;
            }
            if ($parentId === null) {
                return $roomId;
            }
            $detail = $this->client()->get('realty/rooms/'.$roomId.'/')->throw()->json();
            $parent = is_array($detail) ? ($detail['parent_unit'] ?? null) : null;
            $roomParentId = is_array($parent) ? ($parent['id'] ?? null) : $parent;
            if ((string) $roomParentId === (string) $parentId) {
                return $roomId;
            }
            $fallbackId = $fallbackId ?? $roomId;
        }

        return $fallbackId;
    }

    /**
     * Upload Bitrix file field to Alma external-image endpoint.
     *
     * @return int|null Alma external file id
     */
    public function uploadExternalImage(mixed $fileData, string $description = 'Document'): ?int
    {
        $urls = self::extractBitrixFileUrls($fileData);
        $fileUrl = $urls[0] ?? null;
        if ($fileUrl === null || $fileUrl === '') {
            return null;
        }

        $downloaded = $this->downloadRemoteFile($fileUrl);
        if ($downloaded === null) {
            return null;
        }

        try {
            $apiUrl = rtrim(trim((string) config('services.alma.api_url')), '/').'/';
            $apiKey = trim((string) config('services.alma.api_key'));
            if ($apiUrl === '/' || $apiKey === '') {
                throw new RuntimeException('Alma API URL or API key is not configured.');
            }

            $response = Http::baseUrl($apiUrl)
                ->withHeaders(['Api-Key' => $apiKey])
                ->acceptJson()
                ->timeout((int) config('services.alma.timeout', 120))
                ->attach(
                    'image',
                    (string) file_get_contents($downloaded['path']),
                    $downloaded['filename'],
                    ['Content-Type' => $downloaded['mime']]
                )
                ->post('external-image/', ['description' => $description]);

            if ($response->failed()) {
                return null;
            }
            $json = $response->json();

            return is_array($json) && isset($json['id']) ? (int) $json['id'] : null;
        } finally {
            @unlink($downloaded['path']);
        }
    }

    /**
     * @return list<string>
     */
    public static function extractBitrixFileUrls(mixed $fileData): array
    {
        if ($fileData === null || $fileData === '' || $fileData === []) {
            return [];
        }
        if (is_string($fileData)) {
            $trimmed = trim($fileData);

            return $trimmed !== '' ? [$trimmed] : [];
        }
        if (! is_array($fileData)) {
            return [];
        }
        if (! empty($fileData['urlMachine']) && is_string($fileData['urlMachine'])) {
            return [$fileData['urlMachine']];
        }
        $urls = [];
        foreach ($fileData as $item) {
            if (is_string($item) && trim($item) !== '') {
                $urls[] = $item;
                continue;
            }
            if (is_array($item) && ! empty($item['urlMachine']) && is_string($item['urlMachine'])) {
                $urls[] = $item['urlMachine'];
            }
        }

        return $urls;
    }

    /**
     * @return array{path: string, mime: string, filename: string}|null
     */
    private function downloadRemoteFile(string $url): ?array
    {
        $response = Http::withOptions([
            'allow_redirects' => true,
            'verify' => false,
        ])->timeout(120)->get($url);

        if ($response->failed() || $response->body() === '') {
            return null;
        }

        $mime = trim(explode(';', (string) ($response->header('Content-Type') ?: 'application/octet-stream'))[0]);
        $extension = match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'pdf') => 'pdf',
            default => 'bin',
        };
        $path = tempnam(sys_get_temp_dir(), 'alma_upload_').'.'.$extension;
        if (file_put_contents($path, $response->body()) === false) {
            return null;
        }

        return [
            'path' => $path,
            'mime' => $mime !== '' ? $mime : 'application/octet-stream',
            'filename' => 'document.'.$extension,
        ];
    }

    /**
     * GET by external id: 404 → null, other failures → throw.
     *
     * @return array<string, mixed>|null
     */
    private function byExternalId(string $path): ?array
    {
        $response = $this->client()->get($path);
        if ($response->status() === 404) {
            return null;
        }
        $response->throw();
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function json(string $method, string $path, array $payload = []): array
    {
        /** @var Response $response */
        $response = $this->client()->{$method}($path, $payload);
        if ($response->failed()) {
            $body = $response->json() ?? $response->body();
            $detail = is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : (string) $body;
            throw new RuntimeException('Alma API request failed ('.$response->status().'): '.$detail, $response->status());
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Alma API returned invalid JSON for '.$path);
        }

        return $json;
    }

    /**
     * Build configured HTTP client for Alma External API.
     */
    private function client(): PendingRequest
    {
        $apiUrl = rtrim(trim((string) config('services.alma.api_url')), '/').'/';
        $apiKey = trim((string) config('services.alma.api_key'));

        if ($apiUrl === '/' || $apiKey === '') {
            throw new RuntimeException('Alma API URL or API key is not configured.');
        }

        return Http::baseUrl($apiUrl)
            ->withHeaders(['Api-Key' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.alma.timeout', 120));
    }
}
