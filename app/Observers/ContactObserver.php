<?php

namespace App\Observers;

use App\Models\Contact;
use App\Services\EntityPush;
use App\Support\BitrixSync;

class ContactObserver
{
    public function updated(Contact $contact): void
    {
        if (app(BitrixSync::class)->pushPaused()) {
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

        app(EntityPush::class)->pushMappedChanges(
            bitrixId: (int) $contact->bitrix_id,
            changes: $changes,
            fieldMap: $fieldMap,
            method: $updateMethod
        );
    }
}
