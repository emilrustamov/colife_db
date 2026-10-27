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
        $existing = $this->unitByExternalId($externalId);
        if ($existing !== null && isset($existing['id'])) {
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
                'response' => $this->updateRoom($existing['id'], $payload),
            ];
        }
        $payload['external_id'] = $externalId;

        return [
            'method' => 'POST',
            'response' => $this->createRoom($payload),
        ];
    }

    /**
     * Create building by name lookup-or-create pattern used by bridge.
     *
     * @return array<string, mixed>
     */
    public function getOrCreateBuilding(string $buildingName): array
    {
        $list = $this->client()->get('realty/buildings/', ['search' => $buildingName])->throw()->json();
        if (is_array($list)) {
            foreach ($list as $row) {
                if (is_array($row) && strcasecmp((string) ($row['name'] ?? ''), $buildingName) === 0) {
                    return $row;
                }
            }
        }

        return $this->json('post', 'realty/buildings/', ['name' => $buildingName]);
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
