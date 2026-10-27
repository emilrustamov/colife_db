<?php

namespace App\Services\AlmaBridge;

use App\Services\AlmaApi;
use RuntimeException;

class ContactService
{
    public function __construct(
        private readonly AlmaApi $almaApi,
    ) {}

    /**
     * Update Alma client if it already exists (no create on contact webhook).
     *
     * @return array<string, mixed>|null
     */
    public function updateIfExists(int|string $bitrixId, string $projectName): ?array
    {
        if (! BridgeSupport::enabled()) {
            BridgeSupport::log()->info('Alma bridge disabled; contact update skipped', [
                'contact_id' => $bitrixId,
            ]);

            return ['skipped' => true, 'reason' => 'bridge_disabled'];
        }

        $bitrix = BitrixWebhookClient::forProject($projectName);
        $res = $bitrix->call('crm.contact.get', ['id' => $bitrixId]);
        $contact = $res['result'] ?? null;
        if (! is_array($contact)) {
            throw new RuntimeException('Failed to get contact data from Bitrix24');
        }

        $emailField = ProjectMapping::getTenantField('email', $projectName)
            ?: ProjectMapping::getLandlordField('email', $projectName);
        $clientData = BridgeSupport::contactToClient($contact, $emailField, $projectName);

        $existing = $this->almaApi->clientByExternalId((string) $clientData['id']);
        if ($existing === null || empty($existing['id'])) {
            BridgeSupport::log()->info('Contact skipped: not found in Alma', [
                'contact_id' => $bitrixId,
                'project' => $projectName,
            ]);

            return null;
        }

        $payload = array_filter([
            'first_name' => $clientData['first_name'] ?? null,
            'last_name' => $clientData['last_name'] ?? null,
            'phone' => $clientData['phone'] ?? null,
            'email' => $clientData['email'] !== '' ? $clientData['email'] : null,
            'country' => $clientData['country'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        $updated = $this->almaApi->updateClient((int) $existing['id'], $payload);
        BridgeSupport::log()->info('Contact updated in Alma', [
            'contact_id' => $bitrixId,
            'alma_id' => $existing['id'],
            'project' => $projectName,
        ]);

        return ['method' => 'PATCH', 'response' => $updated];
    }
}
