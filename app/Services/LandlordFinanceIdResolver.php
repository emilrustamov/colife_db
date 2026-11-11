<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

class LandlordFinanceIdResolver
{
    public function __construct(
        private readonly AlmaApi $almaApi,
    ) {}

    /**
     * Resolve finance landlord_id/unit_id from Alma unit external_id via active owner contract.
     *
     * Finance API expects external ids: client.external_id + unit.external_id.
     *
     * @return array{landlord_id: string, unit_id: string, alma_client_id: int, alma_unit_id: int}
     */
    public function fromUnitExternalId(string $unitExternalId): array
    {
        $unitExternalId = trim($unitExternalId);
        if ($unitExternalId === '') {
            throw new RuntimeException('Alma unit external_id is empty.');
        }

        $unit = $this->almaApi->unitByExternalId($unitExternalId);
        if ($unit === null || empty($unit['id'])) {
            throw new RuntimeException('Alma unit not found for external_id '.$unitExternalId.'.');
        }

        $almaUnitId = (int) $unit['id'];
        $owner = $this->findOwnerForUnit($almaUnitId);
        if ($owner === null) {
            throw new RuntimeException(
                'No active Alma owner contract for unit '.$unitExternalId.' (alma_unit_id='.$almaUnitId.').'
            );
        }

        return [
            'landlord_id' => (string) $owner['client_external_id'],
            'unit_id' => (string) ($unit['external_id'] ?? $unitExternalId),
            'alma_client_id' => (int) $owner['client_id'],
            'alma_unit_id' => $almaUnitId,
        ];
    }

    /**
     * Validate pair of finance ids against Alma (both must exist as external_ids).
     *
     * @return array{landlord_id: string, unit_id: string, alma_client_id: int, alma_unit_id: int}
     */
    public function fromExternalPair(string $landlordExternalId, string $unitExternalId): array
    {
        $landlordExternalId = trim($landlordExternalId);
        $unitExternalId = trim($unitExternalId);
        if ($landlordExternalId === '' || $unitExternalId === '') {
            throw new RuntimeException('Alma landlord/unit external_id is empty.');
        }

        $client = $this->almaApi->clientByExternalId($landlordExternalId);
        if ($client === null || empty($client['id'])) {
            throw new RuntimeException('Alma client not found for external_id '.$landlordExternalId.'.');
        }

        $unit = $this->almaApi->unitByExternalId($unitExternalId);
        if ($unit === null || empty($unit['id'])) {
            throw new RuntimeException('Alma unit not found for external_id '.$unitExternalId.'.');
        }

        return [
            'landlord_id' => (string) ($client['external_id'] ?? $landlordExternalId),
            'unit_id' => (string) ($unit['external_id'] ?? $unitExternalId),
            'alma_client_id' => (int) $client['id'],
            'alma_unit_id' => (int) $unit['id'],
        ];
    }

    /**
     * Find active owner for Alma unit id (uses cache, scans contracts only on miss).
     *
     * @return array{client_id: int, client_external_id: string, contract_id: int, status: string}|null
     */
    private function findOwnerForUnit(int $almaUnitId): ?array
    {
        if ($almaUnitId <= 0) {
            return null;
        }

        $cacheKey = 'landlord_finance_alma_owner_by_unit_v1';
        /** @var array<string, array{client_id: int, client_external_id: string, contract_id: int, status: string}> $map */
        $map = Cache::get($cacheKey, []);
        if (! is_array($map)) {
            $map = [];
        }

        $key = (string) $almaUnitId;
        if (isset($map[$key])) {
            return $map[$key];
        }

        foreach ($this->almaApi->listOwnerContracts() as $row) {
            $contractId = (int) ($row['id'] ?? 0);
            if ($contractId <= 0) {
                continue;
            }

            $detail = $this->almaApi->ownerContractById($contractId);
            if ($detail === null) {
                continue;
            }

            $usage = is_array($detail['unit_usage'] ?? null) ? $detail['unit_usage'] : [];
            $status = strtolower(trim((string) ($usage['status'] ?? '')));
            if ($status !== '' && $status !== 'active') {
                continue;
            }

            $unitId = (int) ($usage['unit_id'] ?? 0);
            $clientId = (int) ($usage['client_id'] ?? 0);
            if ($unitId <= 0 || $clientId <= 0) {
                continue;
            }

            $client = $this->almaApi->clientById($clientId);
            $clientExternalId = trim((string) ($client['external_id'] ?? ''));
            if ($clientExternalId === '') {
                continue;
            }

            $owner = [
                'client_id' => $clientId,
                'client_external_id' => $clientExternalId,
                'contract_id' => $contractId,
                'status' => $status !== '' ? $status : 'active',
            ];
            $map[(string) $unitId] = $owner;

            if ($unitId === $almaUnitId) {
                Cache::put($cacheKey, $map, now()->addHours(12));

                return $owner;
            }
        }

        Cache::put($cacheKey, $map, now()->addHours(12));

        return null;
    }
}
