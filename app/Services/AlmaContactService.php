<?php

namespace App\Services;

use App\Models\AlmaContact;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AlmaContactService
{
    public function __construct(
        private readonly AlmaApi $almaApi
    ) {
    }

    /**
     * Update Alma contact external_id found by email in the local cache.
     *
     * @return array{contact: AlmaContact, changed: bool}
     */
    public function updateExternalIdByEmail(string $email, string $externalId): array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        if ($normalizedEmail === '') {
            throw new RuntimeException('Email is required.');
        }

        $externalId = trim($externalId);
        if ($externalId === '') {
            throw new RuntimeException('external_id is required.');
        }

        $contact = AlmaContact::query()
            ->where('email', $normalizedEmail)
            ->first();

        if ($contact === null) {
            throw new RuntimeException('Alma contact not found for email.');
        }

        if ((string) $contact->bitrix_id === $externalId) {
            return [
                'contact' => $contact,
                'changed' => false,
            ];
        }

        $this->almaApi->updateClient((int) $contact->alma_id, [
            'external_id' => $externalId,
        ]);

        $contact->bitrix_id = $externalId;
        $contact->save();

        Log::info('Alma contact external_id updated', [
            'alma_id' => $contact->alma_id,
            'email' => $contact->email,
            'bitrix_id' => $externalId,
        ]);

        return [
            'contact' => $contact->fresh(),
            'changed' => true,
        ];
    }

    /**
     * Normalize email for storage and lookup.
     */
    public function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }
}
