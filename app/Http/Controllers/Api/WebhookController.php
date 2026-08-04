<?php

namespace App\Http\Controllers\Api;

use App\Enums\WebhookContext;
use App\Http\Controllers\Controller;
use App\Models\Apartment;
use App\Models\Contact;
use App\Models\Unit;
use App\Services\EntitySync;
use App\Services\TokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function __construct(
        private readonly TokenVerifier $tokenVerifier
    ) {}

    public function __invoke(Request $request, EntitySync $syncService): JsonResponse
    {
        $event = (string) $request->input('event', '');
        $bitrixId = (int) data_get($request->all(), 'data.FIELDS.ID', 0);
        $entityTypeId = $this->resolveEntityTypeId($request);

        Log::channel('bitrix_contacts')->info('Bitrix webhook received', [
            'event' => $event,
            'bitrix_id' => $bitrixId,
            'entity_type_id' => $entityTypeId > 0 ? $entityTypeId : null,
            'domain' => (string) data_get($request->all(), 'auth.domain', ''),
            'member_id' => (string) data_get($request->all(), 'auth.member_id', ''),
            'event_handler_id' => (string) $request->input('event_handler_id', ''),
        ]);

        [$tokenOk, $tokenChannel] = $this->tokenVerifier->verify($request, WebhookContext::Crm);
        if (! $tokenOk) {
            Log::channel($tokenChannel)->warning('Bitrix webhook rejected by token', [
                'event' => $event,
                'bitrix_id' => $bitrixId,
                'entity_type_id' => $entityTypeId > 0 ? $entityTypeId : null,
                'has_application_token' => (string) data_get($request->all(), 'auth.application_token', '') !== '',
            ]);

            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        if ($bitrixId <= 0) {
            Log::channel('bitrix_contacts')->warning('Bitrix webhook missing entity id', [
                'event' => $event,
                'payload_data' => $request->input('data'),
            ]);

            return response()->json(['success' => false, 'message' => 'Missing entity ID'], 422);
        }

        if ($this->isContactDeleteEvent($event)) {
            $updated = $syncService->markDeleted($bitrixId, 'contacts');
            $this->logContactDelete($bitrixId);
            Log::channel('bitrix_contacts')->info('Bitrix webhook contact delete handled', [
                'bitrix_id' => $bitrixId,
                'updated_rows' => $updated,
                'operation' => 'delete',
            ]);

            return response()->json(['success' => true, 'event' => $event, 'bitrix_id' => $bitrixId, 'entity' => 'contacts']);
        }

        if ($this->isContactEvent($event)) {
            $synced = $syncService->syncOne($bitrixId, 'contacts');
            if (! $synced) {
                Log::channel('bitrix_contacts')->warning('Bitrix webhook: contact not found in CRM', [
                    'event' => $event,
                    'bitrix_id' => $bitrixId,
                ]);

                return response()->json(['success' => false, 'message' => 'Contact not found in Bitrix'], 404);
            }

            Log::channel('bitrix_contacts')->info('Bitrix webhook entity synced', [
                'event' => $event,
                'entity' => 'contacts',
                'bitrix_id' => $bitrixId,
            ]);

            return response()->json(['success' => true, 'event' => $event, 'bitrix_id' => $bitrixId, 'entity' => 'contacts']);
        }

        if ($this->isDynamicItemEvent($event)) {
            $entity = $this->resolveDynamicEntity($entityTypeId);
            if ($entity === null) {
                Log::channel('bitrix_contacts')->info('Bitrix webhook dynamic entity ignored (unsupported type)', [
                    'event' => $event,
                    'bitrix_id' => $bitrixId,
                    'entity_type_id' => $entityTypeId > 0 ? $entityTypeId : null,
                ]);

                return response()->json([
                    'success' => true,
                    'event' => $event,
                    'bitrix_id' => $bitrixId,
                    'entity_type_id' => $entityTypeId > 0 ? $entityTypeId : null,
                    'message' => 'Dynamic entity ignored',
                ]);
            }

            if ($event === 'ONCRMITEMDELETE') {
                $updated = $syncService->markDeleted($bitrixId, $entity);
                $this->logDynamicDelete($entity, $bitrixId);
                Log::channel('bitrix_contacts')->info('Bitrix webhook dynamic entity delete handled', [
                    'event' => $event,
                    'entity' => $entity,
                    'bitrix_id' => $bitrixId,
                    'updated_rows' => $updated,
                ]);

                return response()->json([
                    'success' => true,
                    'event' => $event,
                    'entity' => $entity,
                    'bitrix_id' => $bitrixId,
                    'entity_type_id' => $entityTypeId > 0 ? $entityTypeId : null,
                ]);
            }

            $synced = $syncService->syncOne($bitrixId, $entity);
            if (! $synced) {
                Log::channel('bitrix_contacts')->warning('Bitrix webhook dynamic entity not found in CRM', [
                    'event' => $event,
                    'entity' => $entity,
                    'bitrix_id' => $bitrixId,
                    'entity_type_id' => $entityTypeId > 0 ? $entityTypeId : null,
                ]);

                return response()->json(['success' => false, 'message' => 'Entity not found in Bitrix'], 404);
            }

            Log::channel('bitrix_contacts')->info('Bitrix webhook entity synced', [
                'event' => $event,
                'entity' => $entity,
                'bitrix_id' => $bitrixId,
                'entity_type_id' => $entityTypeId > 0 ? $entityTypeId : null,
            ]);

            return response()->json([
                'success' => true,
                'event' => $event,
                'entity' => $entity,
                'bitrix_id' => $bitrixId,
                'entity_type_id' => $entityTypeId > 0 ? $entityTypeId : null,
            ]);
        }

        Log::channel('bitrix_contacts')->info('Bitrix webhook ignored event', [
            'event' => $event,
            'bitrix_id' => $bitrixId,
            'entity_type_id' => $entityTypeId > 0 ? $entityTypeId : null,
        ]);

        return response()->json(['success' => true, 'message' => 'Event ignored', 'event' => $event]);
    }

    private function isContactEvent(string $event): bool
    {
        return in_array($event, ['ONCRMCONTACTADD', 'ONCRMCONTACTUPDATE'], true);
    }

    private function isContactDeleteEvent(string $event): bool
    {
        return $event === 'ONCRMCONTACTDELETE';
    }

    private function isDynamicItemEvent(string $event): bool
    {
        return in_array($event, [
            'ONCRMITEMADD',
            'ONCRMITEMUPDATE',
            'ONCRMITEMDELETE',
            'ONCRMDYNAMICITEMADD',
            'ONCRMDYNAMICITEMUPDATE',
            'ONCRMDYNAMICITEMDELETE',
        ], true);
    }

    private function resolveEntityTypeId(Request $request): int
    {
        $entityTypeId = (int) data_get($request->all(), 'data.FIELDS.ENTITY_TYPE_ID', 0);
        if ($entityTypeId > 0) {
            return $entityTypeId;
        }

        $entity = trim((string) data_get($request->all(), 'data.FIELDS.ENTITY', ''));
        if (preg_match('/DYNAMIC_(\d+)/', $entity, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function resolveDynamicEntity(int $entityTypeId): ?string
    {
        return match ($entityTypeId) {
            144 => 'apartments',
            148 => 'apartment_ownerships',
            167 => 'units',
            183 => 'unit_stays',
            default => null,
        };
    }

    private function logContactDelete(int $bitrixId): void
    {
        $contact = Contact::query()->where('bitrix_id', $bitrixId)->first(['id', 'bitrix_id']);
        if ($contact === null) {
            return;
        }

        $now = now();

        DB::table('activity_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => 'bitrix.contact.deleted',
            'subject_type' => Contact::class,
            'subject_id' => $contact->id,
            'user_id' => null,
            'old_values' => null,
            'new_values' => json_encode([
                'bitrix_id' => (int) $contact->bitrix_id,
                'is_deleted' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'happened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function logDynamicDelete(string $entity, int $bitrixId): void
    {
        if ($entity === 'apartments') {
            $subjectType = Apartment::class;
            $event = 'bitrix.apartment.deleted';
            $row = DB::table('apartments')->where('bitrix_id', $bitrixId)->first(['id', 'bitrix_id']);
        } elseif ($entity === 'units') {
            $subjectType = Unit::class;
            $event = 'bitrix.unit.deleted';
            $row = DB::table('units')->where('bitrix_id', $bitrixId)->first(['id', 'bitrix_id']);
        } else {
            return;
        }

        if ($row === null || ! is_string($row->id) || $row->id === '') {
            return;
        }

        $now = now();
        DB::table('activity_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $row->id,
            'user_id' => null,
            'old_values' => null,
            'new_values' => json_encode([
                'bitrix_id' => (int) $row->bitrix_id,
                'is_deleted' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'happened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
