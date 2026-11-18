<?php

namespace App\Services\AlmaBridge;

use App\Services\AlmaApi;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TenantContractService
{
    public function __construct(
        private readonly AlmaApi $almaApi,
    ) {}

    /**
     * Create tenant contract in Alma from Bitrix id.
     *
     * @return array<string, mixed>
     */
    public function create(int|string $bitrixId, string $projectName): array
    {
        return $this->upsert($bitrixId, $projectName, ['force_create' => true]);
    }

    /**
     * Update tenant contract in Alma from Bitrix id.
     *
     * @return array<string, mixed>
     */
    public function update(int|string $bitrixId, string $projectName): array
    {
        return $this->upsert($bitrixId, $projectName, ['force_update' => true]);
    }

    /**
     * Upsert tenant contract (webhook / manual): create, update or archive by stage.
     *
     * @param  array{skip_if_integration_update?: bool, force_create?: bool, force_update?: bool}  $options
     * @return array<string, mixed>
     */
    public function upsert(int|string $bitrixId, string $projectName, array $options = []): array
    {
        if (! BridgeSupport::enabled()) {
            BridgeSupport::log()->info('Alma bridge disabled; tenant contract upsert skipped', [
                'contract_id' => $bitrixId,
                'project' => $projectName,
            ]);

            return ['skipped' => true, 'reason' => 'bridge_disabled'];
        }

        $bitrix = BitrixWebhookClient::forProject($projectName);
        $contractTypeId = ProjectMapping::contractTypeId($projectName);
        $contractData = [];

        try {
            $contractRes = $bitrix->call('crm.item.get', [
                'entityTypeId' => $contractTypeId,
                'id' => $bitrixId,
            ]);
            $contractData = $contractRes['result']['item'] ?? null;
            if (! is_array($contractData)) {
                throw new RuntimeException('Contract not found in Bitrix24');
            }

            if (! empty($options['skip_if_integration_update'])) {
                if (BridgeSupport::isWebhookEcho(
                    $bitrix,
                    $contractTypeId,
                    $bitrixId,
                    $contractData,
                    $bitrix->restUserId()
                )) {
                    BridgeSupport::log()->info('Contract sync skipped: webhook echo from Alma timeline comment', [
                        'contract_id' => $bitrixId,
                    ]);

                    return ['skipped' => true, 'reason' => 'alma_comment_echo'];
                }
            }

            if ($this->shouldArchive($contractData, $projectName)) {
                $externalId = (string) ($contractData['id'] ?? $bitrixId);
                $this->almaApi->archiveTenant($externalId, true);
                BridgeSupport::log()->info('Contract archived in Alma', [
                    'contract_id' => $bitrixId,
                    'project' => $projectName,
                ]);

                return ['method' => 'ARCHIVE', 'external_id' => $externalId];
            }

            $contactId = $contractData['contactId'] ?? $contractData['CONTACT_ID'] ?? null;
            if ($contactId === null || $contactId === '' || (int) $contactId <= 0) {
                throw new InvalidArgumentException('Contact is not linked to the contract (contactId is empty)');
            }
            $contactRes = $bitrix->call('crm.contact.get', ['id' => $contactId]);
            $contact = $contactRes['result'] ?? null;
            if (! is_array($contact)) {
                throw new RuntimeException('Failed to get contact data from Bitrix24');
            }

            $emailField = ProjectMapping::getTenantField('email', $projectName);
            $clientData = BridgeSupport::contactToClient($contact, $emailField, $projectName);
            $clientData = $this->enrichClientData($clientData, $contractData, $projectName);

            $unitField = ProjectMapping::getContractField('unit_id', $projectName);
            $unitExternalId = (string) (ProjectMapping::itemValue($contractData, $unitField) ?? '');
            if ($unitExternalId === '') {
                throw new InvalidArgumentException('Unit is not linked to the contract');
            }

            $contractTypeField = ProjectMapping::getContractField('contract_type', $projectName);
            $contractType = (string) (ProjectMapping::itemValue($contractData, $contractTypeField) ?? '');
            $typeContract = ProjectMapping::mapContractType($contractType, $projectName);
            if ($typeContract === null) {
                throw new InvalidArgumentException("Unknown contract type: {$contractType}");
            }

            $clientAlmaId = $this->ensureClientExists($clientData, $contractType, $projectName);
            $unitAlmaId = $this->findAlmaObjectById($unitExternalId);

            $startField = ProjectMapping::getContractField('start_date', $projectName);
            $endField = ProjectMapping::getContractField('end_date', $projectName);
            $payload = [
                'external_id' => (string) ($contractData['id'] ?? $bitrixId),
                'unit_id' => $unitAlmaId,
                'client_id' => $clientAlmaId,
                'name' => (string) ($contractData['title'] ?? ''),
                'start_date' => BridgeSupport::formatDateForAlma(ProjectMapping::itemValue($contractData, $startField)),
                'end_date' => BridgeSupport::formatDateForAlma(ProjectMapping::itemValue($contractData, $endField)),
                'price' => number_format((float) ($contractData['opportunity'] ?? 0), 2, '.', ''),
                'type_contract' => $typeContract,
                'rent_type' => $typeContract === 'Ejari' ? 'long_term' : 'short_term',
            ];

            $existing = $this->almaApi->tenantByExternalId($payload['external_id']);

            if (! empty($options['force_create'])) {
                if ($existing !== null && BridgeSupport::isAlmaContractArchived($existing)) {
                    $this->clearArchivedId($existing);
                    $existing = null;
                }
                if ($existing !== null) {
                    throw new RuntimeException('Contract already exists; use update endpoint');
                }
                $created = $this->almaApi->createTenantContract($payload);
                BridgeSupport::log()->info('Contract sync succeeded', [
                    'contract_id' => $bitrixId,
                    'alma_id' => $created['id'] ?? null,
                    'method' => 'POST',
                ]);

                return $this->finishSync($bitrix, $contractTypeId, $bitrixId, [
                    'method' => 'POST',
                    'response' => $created,
                ], $clientAlmaId);
            }

            if (! empty($options['force_update'])) {
                if ($existing === null) {
                    throw new RuntimeException('Contract not found in Alma; use create endpoint');
                }
                if (BridgeSupport::isAlmaContractArchived($existing)) {
                    $this->clearArchivedId($existing);
                    $created = $this->almaApi->createTenantContract($payload);
                    BridgeSupport::log()->info('Contract recreated after archive release', [
                        'contract_id' => $bitrixId,
                        'alma_id' => $created['id'] ?? null,
                    ]);

                    return $this->finishSync($bitrix, $contractTypeId, $bitrixId, [
                        'method' => 'POST',
                        'response' => $created,
                    ], $clientAlmaId);
                }
                unset($payload['client_id']);
                $updated = $this->almaApi->updateTenantContract($existing['id'], $payload);
                BridgeSupport::log()->info('Contract sync succeeded', [
                    'contract_id' => $bitrixId,
                    'alma_id' => $existing['id'],
                    'method' => 'PATCH',
                ]);

                return ['method' => 'PATCH', 'response' => $updated];
            }

            if ($existing !== null) {
                if (BridgeSupport::isAlmaContractArchived($existing)) {
                    $this->clearArchivedId($existing);
                    $created = $this->almaApi->createTenantContract($payload);
                    BridgeSupport::log()->info('Contract sync succeeded', [
                        'contract_id' => $bitrixId,
                        'alma_id' => $created['id'] ?? null,
                        'method' => 'POST',
                    ]);

                    return $this->finishSync($bitrix, $contractTypeId, $bitrixId, [
                        'method' => 'POST',
                        'response' => $created,
                    ], $clientAlmaId);
                }
                unset($payload['client_id']);
                try {
                    $updated = $this->almaApi->updateTenantContract($existing['id'], $payload);
                } catch (RuntimeException $e) {
                    if (AlmaApi::isArchivedConflict($e)) {
                        $this->clearArchivedId($existing);
                        $created = $this->almaApi->createTenantContract($payload);
                        BridgeSupport::log()->info('Contract recreated after archive usage error', [
                            'contract_id' => $bitrixId,
                            'alma_id' => $created['id'] ?? null,
                        ]);

                        return $this->finishSync($bitrix, $contractTypeId, $bitrixId, [
                            'method' => 'POST',
                            'response' => $created,
                        ], $clientAlmaId);
                    }
                    throw $e;
                }
                BridgeSupport::log()->info('Contract sync succeeded', [
                    'contract_id' => $bitrixId,
                    'alma_id' => $existing['id'],
                    'method' => 'PATCH',
                ]);

                return ['method' => 'PATCH', 'response' => $updated];
            }

            $created = $this->almaApi->createTenantContract($payload);
            BridgeSupport::log()->info('Contract sync succeeded', [
                'contract_id' => $bitrixId,
                'alma_id' => $created['id'] ?? null,
                'method' => 'POST',
            ]);

            return $this->finishSync($bitrix, $contractTypeId, $bitrixId, [
                'method' => 'POST',
                'response' => $created,
            ], $clientAlmaId);
        } catch (Throwable $e) {
            try {
                $startField = ProjectMapping::getContractField('start_date', $projectName);
                $endField = ProjectMapping::getContractField('end_date', $projectName);
                $unitField = ProjectMapping::getContractField('unit_id', $projectName);
                $unitExternalId = (string) (ProjectMapping::itemValue($contractData, $unitField) ?? '');
                $almaUnitId = null;
                if ($unitExternalId !== '') {
                    $almaUnitId = $this->almaApi->rentalObjectByBitrixId($unitExternalId)['id'] ?? null;
                }
                BridgeSupport::addCrmTimelineComment(
                    $bitrix,
                    $contractTypeId,
                    $bitrixId,
                    BridgeSupport::formatSyncErrorComment(
                        $e->getMessage(),
                        $almaUnitId,
                        ProjectMapping::itemValue($contractData, $startField),
                        ProjectMapping::itemValue($contractData, $endField)
                    )
                );
            } catch (Throwable) {
            }
            BridgeSupport::log()->error('Webhook: tenant contract sync failed', [
                'entity_id' => $bitrixId,
                'project' => $projectName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $bitrixData
     */
    private function shouldArchive(array $bitrixData, string $projectName): bool
    {
        $stageIds = ProjectMapping::tenantArchiveStages($projectName);
        $current = $bitrixData['stageId'] ?? null;

        return $current !== null && in_array((string) $current, $stageIds, true);
    }

    /**
     * @param  array<string, mixed>  $clientData
     */
    private function ensureClientExists(array $clientData, string $contractType, string $projectName): string
    {
        $isAirbnb = ProjectMapping::airbnbTypeId($projectName) === (string) $contractType;
        if (empty($clientData['email']) && $isAirbnb) {
            $clientData['email'] = 'airbnb'.bin2hex(random_bytes(2)).'@airbnb.colife';
        }
        if ($isAirbnb && ! empty($clientData['last_name']) && ! str_ends_with($clientData['last_name'], ' airbnb')) {
            $clientData['last_name'] = trim($clientData['last_name']).' airbnb';
        }

        $externalId = (string) $clientData['id'];
        $lock = Cache::lock('alma-bridge:create-client:'.$externalId, 30);

        return $lock->block(15, function () use ($clientData, $contractType, $projectName, $externalId): string {
            $existing = $this->almaApi->clientByExternalId($externalId);
            if ($existing !== null && isset($existing['id'])) {
                return (string) $existing['id'];
            }

            $noInvitation = ProjectMapping::noInviteTypes($projectName);
            $sendInvitation = ! in_array((string) $contractType, $noInvitation, true);
            $payload = array_filter([
                'external_id' => $clientData['id'],
                'first_name' => $clientData['first_name'] ?? '',
                'last_name' => $clientData['last_name'] ?? '',
                'phone' => $clientData['phone'] ?? '',
                'country' => $clientData['country'] ?? 4,
                'email' => $clientData['email'] ?? null,
                'birthday' => $clientData['birthday'] ?? null,
                'passport' => $clientData['passport'] ?? null,
                'passport_scan' => $clientData['passport_scan'] ?? null,
                'id_scan' => $clientData['id_scan'] ?? null,
                'is_send_password_when_creating' => $sendInvitation,
            ], static fn ($v) => $v !== null && $v !== '');

            $created = $this->almaApi->createClient($payload);

            return (string) $created['id'];
        });
    }

    private function findAlmaObjectById(string $bitrixId): int|string
    {
        $object = $this->almaApi->rentalObjectByBitrixId($bitrixId);
        if ($object === null || ! isset($object['id'])) {
            throw new RuntimeException('Alma rental object not found for Bitrix id='.$bitrixId);
        }

        return $object['id'];
    }

    /**
     * Timeline "created" comment only on POST (legacy parity) — never on PATCH.
     *
     * @param  array{method: string, response?: array<string, mixed>}  $result
     * @return array{method: string, response?: array<string, mixed>}
     */
    private function finishSync(
        BitrixWebhookClient $bitrix,
        int $contractTypeId,
        int|string $bitrixId,
        array $result,
        int|string|null $almaClientId = null,
    ): array {
        if (($result['method'] ?? '') !== 'POST') {
            return $result;
        }

        $clientId = $almaClientId
            ?? ($result['response']['unit_usage']['client_id'] ?? null)
            ?? ($result['response']['client']['id'] ?? null);
        if ($clientId === null || $clientId === '') {
            return $result;
        }

        try {
            BridgeSupport::addCrmTimelineComment(
                $bitrix,
                $contractTypeId,
                $bitrixId,
                BridgeSupport::formatContractCreatedComment($clientId)
            );
        } catch (Throwable $commentError) {
            BridgeSupport::log()->warning('Failed to add Bitrix success comment on contract', [
                'contract_id' => $bitrixId,
                'error' => $commentError->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $clientData
     * @param  array<string, mixed>  $contractData
     * @return array<string, mixed>
     */
    private function enrichClientData(array $clientData, array $contractData, string $projectName): array
    {
        $passportField = ProjectMapping::getTenantField('passport_number', $projectName);
        $idScanField = ProjectMapping::getTenantField('id_scan', $projectName);
        if ($passportField === null && $idScanField === null) {
            return $clientData;
        }

        if ($passportField !== null) {
            $passport = ProjectMapping::itemValue($contractData, $passportField);
            if (is_array($passport)) {
                $passport = $passport[0] ?? '';
            }
            $passport = trim((string) $passport);
            if ($passport !== '') {
                $clientData['passport'] = $passport;
            }
        }

        if ($idScanField !== null) {
            $scanData = ProjectMapping::itemValue($contractData, $idScanField);
            $scanFields = ['id_scan', 'passport_scan'];
            foreach (AlmaApi::extractBitrixFileUrls($scanData) as $index => $fileUrl) {
                $almaField = $scanFields[$index] ?? null;
                if ($almaField === null) {
                    break;
                }
                $scanId = $this->almaApi->uploadExternalImage(
                    $fileUrl,
                    $index === 0 ? 'ID scan' : 'Passport scan'
                );
                if ($scanId !== null) {
                    $clientData[$almaField] = $scanId;
                }
            }
        }

        return $clientData;
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private function clearArchivedId(array $contract): void
    {
        $almaId = $contract['id'] ?? null;
        $externalId = $contract['external_id'] ?? null;
        if ($almaId === null || $externalId === null || $externalId === '') {
            return;
        }
        $newExternalId = $externalId.'_old_'.$almaId;
        $this->almaApi->updateTenantContract($almaId, ['external_id' => $newExternalId]);
        BridgeSupport::log()->info('Released external_id of archived contract', [
            'alma_id' => $almaId,
            'from' => $externalId,
            'to' => $newExternalId,
        ]);
    }
}
