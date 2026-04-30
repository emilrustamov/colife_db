<?php

namespace App\Observers;

use App\Models\Contact;
use App\Services\BitrixEntityPushService;
use App\Support\BitrixSyncContext;

class ContactObserver
{
    public function updated(Contact $contact): void
    {
        if (app(BitrixSyncContext::class)->isContactPushSuspended()) {
            return;
        }

        $tracked = ['first_name', 'last_name', 'birth_date'];
        $changes = [];

        foreach ($tracked as $field) {
            if ($contact->wasChanged($field)) {
                $changes[$field] = $contact->{$field};
            }
        }

        if ($changes === []) {
            return;
        }

        /** @var array<string, string> $fieldMap */
        $fieldMap = (array) config('services.bitrix_contacts.push.field_map', []);
        $updateMethod = (string) config('services.bitrix_contacts.push.update_method', 'crm.contact.update.json');

        app(BitrixEntityPushService::class)->pushMappedChanges(
            bitrixId: (int) $contact->bitrix_id,
            changes: $changes,
            fieldMap: $fieldMap,
            method: $updateMethod
        );
    }
}
