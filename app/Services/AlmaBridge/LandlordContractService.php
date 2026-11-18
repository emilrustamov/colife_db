<?php

namespace App\Services\AlmaBridge;

use App\Services\AlmaApi;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class LandlordContractService
{
    public function __construct(
        private readonly AlmaApi $almaApi,
    ) {}

    /**
     * Create landlord contract in Alma from Bitrix id.
     *
     * @return array<string, mixed>
     */
    public function create(int|string $bitrixId, string $projectName): array
    {
        return $this->upsert($bitrixId, $projectName, ['force_create' => true]);
    }

    /**
     * Update landlord contract in Alma from Bitrix id.
     *
     * @return array<string, mixed>
     */
    public function update(int|string $bitrixId, string $projectName): array
    {
        return $this->upsert($bitrixId, $projectName, ['force_update' => true]);
    }

    /**
     * Upsert landlord (owner) contract SPA into Alma.
     *
     * @param  array{skip_if_integration_update?: bool, force_create?: bool, force_update?: bool}  $options
     * @return array<string, mixed>
     */
    public function upsert(int|string $bitrixId, string $projectName, array $options = []): array
    {
        if (! BridgeSupport::enabled()) {
            return ['skipped' => true, 'reason' => 'bridge_disabled'];
        }

        $llTypeId = ProjectMapping::landlordTypeId($projectName);
        if ($llTypeId === null) {
            return ['skipped' => true, 'reason' => 'll_not_configured'];
        }

        $bitrix = BitrixWebhookClient::forProject($projectName);

        try {
            $res = $bitrix->call('crm.item.get', [
                'entityTypeId' => $llTypeId,
                'id' => $bitrixId,
            ]);
            $item = $res['result']['item'] ?? null;
            if (! is_array($item)) {
                throw new RuntimeException('Landlord contract not found');
            }

            if (! empty($options['skip_if_integration_update'])) {
                if (BridgeSupport::isWebhookEcho(
                    $bitrix,
                    $llTypeId,
                    $bitrixId,
                    $item,
                    $bitrix->restUserId()
                )) {
                    BridgeSupport::log()->info('Landlord contract sync skipped: webhook echo from Alma timeline comment', [
                        'contract_id' => $bitrixId,
                    ]);

                    return ['skipped' => true, 'reason' => 'alma_comment_echo'];
                }
            }

            $apartmentField = ProjectMapping::landlordContractField('apartment_id', $projectName);
            $apartmentExternalId = (string) (ProjectMapping::itemValue($item, $apartmentField) ?? '');
            if ($apartmentExternalId === '') {
                throw new InvalidArgumentException('Apartment is not linked to landlord contract');
            }
            $unit = $this->almaApi->rentalObjectByBitrixId($apartmentExternalId);
            if ($unit === null || empty($unit['id'])) {
                throw new RuntimeException('Apartment not found in Alma');
            }

            $contactId = $item['contactId'] ?? null;
            if (! $contactId) {
                throw new InvalidArgumentException('Landlord contact is empty');
            }
            $contactRes = $bitrix->call('crm.contact.get', ['id' => $contactId]);
            $contact = $contactRes['result'] ?? null;
            if (! is_array($contact)) {
                throw new RuntimeException('Landlord contact not found');
            }
            $emailField = ProjectMapping::getLandlordField('email', $projectName);
            $clientData = BridgeSupport::contactToClient($contact, $emailField, $projectName);
            $clientData = $this->enrichLandlordClientData($clientData, $item, $projectName);
            $clientExternalId = (string) $clientData['id'];
            $existingClient = Cache::lock(
                'alma-bridge:create-client:'.$clientExternalId,
                30
            )->block(15, function () use ($clientData, $clientExternalId) {
                $existingClient = $this->almaApi->clientByExternalId($clientExternalId);
                if ($existingClient !== null) {
                    return $existingClient;
                }

                $payload = array_filter([
                    'external_id' => $clientData['id'],
                    'first_name' => $clientData['first_name'] ?? '',
                    'last_name' => $clientData['last_name'] ?? '',
                    'phone' => $clientData['phone'] ?? '',
                    'email' => ($clientData['email'] ?? '') !== '' ? $clientData['email'] : null,
                    'country' => $clientData['country'] ?? 4,
                    'passport' => $clientData['passport'] ?? null,
                    'id_scan' => $clientData['id_scan'] ?? null,
                ], static fn ($v) => $v !== null && $v !== '');

                return $this->almaApi->createClient($payload);
            });

            $startField = ProjectMapping::landlordContractField('start_date', $projectName);
            $endField = ProjectMapping::landlordContractField('end_date', $projectName);
            $workModelField = ProjectMapping::landlordContractField('work_model', $projectName);
            $workRaw = ProjectMapping::itemValue($item, $workModelField);
            if (is_array($workRaw)) {
                $workRaw = $workRaw[0] ?? null;
            }

            $payload = [
                'external_id' => (string) ($item['id'] ?? $bitrixId),
                'name' => (string) ($item['title'] ?? ''),
                'client_id' => $existingClient['id'],
                'unit_id' => $unit['id'],
                'start_date' => BridgeSupport::formatDateForAlma(ProjectMapping::itemValue($item, $startField)),
                'end_date' => BridgeSupport::formatDateForAlma(ProjectMapping::itemValue($item, $endField)),
                'work_model' => ProjectMapping::mapWorkModel($workRaw, $projectName),
            ];

            $scanMap = [
                'contract_scan' => 'contract_with_client_scan',
                'title_deed_scan' => 'property_title_deed_scan',
                'pml_scan' => 'property_management_letter_scan',
                'dtcm_scan' => 'dtcm_permit_scan',
            ];
            foreach ($scanMap as $mapKey => $almaKey) {
                $field = ProjectMapping::landlordContractField($mapKey, $projectName);
                if ($field === null) {
                    continue;
                }
                $scanId = $this->almaApi->uploadExternalImage(
                    ProjectMapping::itemValue($item, $field),
                    'Landlord contract document'
                );
                if ($scanId !== null) {
                    $payload[$almaKey] = $scanId;
                }
            }

            $pmlStartField = ProjectMapping::landlordContractField('pml_start_date', $projectName);
            $pmlEndField = ProjectMapping::landlordContractField('pml_end_date', $projectName);
            if ($pmlStartField) {
                $pmlStart = BridgeSupport::formatDateForAlma(ProjectMapping::itemValue($item, $pmlStartField));
                if ($pmlStart !== '') {
                    $payload['pml_start_date'] = $pmlStart;
                }
            }
            if ($pmlEndField) {
                $pmlEnd = BridgeSupport::formatDateForAlma(ProjectMapping::itemValue($item, $pmlEndField));
                if ($pmlEnd !== '') {
                    $payload['pml_end_date'] = $pmlEnd;
                }
            }

            $existing = $this->almaApi->landlordByExternalId($payload['external_id']);

            if (! empty($options['force_create'])) {
                if ($existing !== null) {
                    throw new RuntimeException('Landlord contract already exists');
                }
                $created = $this->almaApi->createLandlordContract($payload);
                BridgeSupport::log()->info('Landlord contract sync succeeded', [
                    'contract_id' => $bitrixId,
                    'method' => 'POST',
                ]);

                return $this->finishCreateComment(
                    $bitrix,
                    $llTypeId,
                    $bitrixId,
                    ['method' => 'POST', 'response' => $created],
                    $existingClient['id'] ?? null
                );
            }

            if (! empty($options['force_update']) || $existing !== null) {
                if ($existing === null) {
                    throw new RuntimeException('Landlord contract not found in Alma');
                }
                $updated = $this->almaApi->updateLandlordContract($existing['id'], $payload);
                BridgeSupport::log()->info('Landlord contract sync succeeded', [
                    'contract_id' => $bitrixId,
                    'method' => 'PATCH',
                ]);

                return ['method' => 'PATCH', 'response' => $updated];
            }

            $created = $this->almaApi->createLandlordContract($payload);
            BridgeSupport::log()->info('Landlord contract sync succeeded', [
                'contract_id' => $bitrixId,
                'method' => 'POST',
            ]);

            return $this->finishCreateComment(
                $bitrix,
                $llTypeId,
                $bitrixId,
                ['method' => 'POST', 'response' => $created],
                $existingClient['id'] ?? null
            );
        } catch (Throwable $e) {
            BridgeSupport::log()->error('Webhook: landlord contract sync failed', [
                'entity_id' => $bitrixId,
                'project' => $projectName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Timeline "created" comment only on POST — never on PATCH.
     *
     * @param  array{method: string, response?: array<string, mixed>}  $result
     * @return array{method: string, response?: array<string, mixed>}
     */
    private function finishCreateComment(
        BitrixWebhookClient $bitrix,
        int $entityTypeId,
        int|string $bitrixId,
        array $result,
        int|string|null $almaClientId = null,
    ): array {
        if (($result['method'] ?? '') !== 'POST') {
            return $result;
        }

        $clientId = $almaClientId
            ?? ($result['response']['client_id'] ?? null)
            ?? ($result['response']['client']['id'] ?? null);
        if ($clientId === null || $clientId === '') {
            return $result;
        }

        try {
            BridgeSupport::addCrmTimelineComment(
                $bitrix,
                $entityTypeId,
                $bitrixId,
                BridgeSupport::formatContractCreatedComment($clientId)
            );
        } catch (Throwable $commentError) {
            BridgeSupport::log()->warning('Failed to add Bitrix success comment on landlord contract', [
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
    private function enrichLandlordClientData(array $clientData, array $contractData, string $projectName): array
    {
        $passportField = ProjectMapping::getLandlordField('passport_number', $projectName);
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

        $idScanField = ProjectMapping::getLandlordField('id_scan', $projectName);
        if ($idScanField !== null) {
            $scanId = $this->almaApi->uploadExternalImage(
                ProjectMapping::itemValue($contractData, $idScanField),
                'ID scan'
            );
            if ($scanId !== null) {
                $clientData['id_scan'] = $scanId;
            }
        }

        return $clientData;
    }
}
