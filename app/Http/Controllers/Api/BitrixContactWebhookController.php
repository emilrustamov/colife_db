<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BitrixEntitySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BitrixContactWebhookController extends Controller
{
    /**
     * Handle incoming Bitrix CRM contact events.
     */
    public function __invoke(Request $request, BitrixEntitySyncService $syncService): JsonResponse
    {
        $event = (string) $request->input('event', '');
        $bitrixId = (int) data_get($request->all(), 'data.FIELDS.ID', 0);

        Log::channel('bitrix_contacts')->info('Bitrix webhook received', [
            'event' => $event,
            'bitrix_id' => $bitrixId,
            'domain' => (string) data_get($request->all(), 'auth.domain', ''),
            'member_id' => (string) data_get($request->all(), 'auth.member_id', ''),
            'event_handler_id' => (string) $request->input('event_handler_id', ''),
        ]);

        if (! $this->isValidToken($request)) {
            Log::channel('bitrix_contacts')->warning('Bitrix webhook rejected by token', [
                'event' => $event,
                'bitrix_id' => $bitrixId,
                'has_application_token' => (string) data_get($request->all(), 'auth.application_token', '') !== '',
            ]);

            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        if ($bitrixId <= 0) {
            Log::channel('bitrix_contacts')->warning('Bitrix webhook missing contact id', [
                'event' => $event,
                'payload_data' => $request->input('data'),
            ]);

            return response()->json(['success' => false, 'message' => 'Missing contact ID'], 422);
        }

        if ($event === 'ONCRMCONTACTDELETE') {
            $updated = $syncService->markItemDeleted($bitrixId);
            Log::channel('bitrix_contacts')->info('Bitrix webhook contact delete handled', [
                'bitrix_id' => $bitrixId,
                'updated_rows' => $updated,
                'operation' => 'delete',
            ]);

            return response()->json(['success' => true, 'event' => $event, 'bitrix_id' => $bitrixId]);
        }

        if (! in_array($event, ['ONCRMCONTACTADD', 'ONCRMCONTACTUPDATE'], true)) {
            Log::channel('bitrix_contacts')->info('Bitrix webhook ignored event', [
                'event' => $event,
                'bitrix_id' => $bitrixId,
            ]);

            return response()->json(['success' => true, 'message' => 'Event ignored', 'event' => $event]);
        }

        $synced = $syncService->syncSingleItemByBitrixId($bitrixId);
        if (! $synced) {
            Log::channel('bitrix_contacts')->warning('Bitrix webhook: contact not found in CRM', [
                'event' => $event,
                'bitrix_id' => $bitrixId,
            ]);

            return response()->json(['success' => false, 'message' => 'Contact not found in Bitrix'], 404);
        }

        Log::channel('bitrix_contacts')->info('Bitrix webhook contact synced', [
            'event' => $event,
            'bitrix_id' => $bitrixId,
            'operation' => $event === 'ONCRMCONTACTADD' ? 'create' : 'update',
        ]);

        return response()->json(['success' => true, 'event' => $event, 'bitrix_id' => $bitrixId]);
    }

    /**
     * Validate webhook token.
     */
    private function isValidToken(Request $request): bool
    {
        $expected = (string) config('services.bitrix_contacts.event_token');
        if ($expected === '') {
            Log::channel('bitrix_contacts')->error('Bitrix webhook token config is empty');

            return false;
        }

        $incoming = (string) data_get($request->all(), 'auth.application_token', '');
        if ($incoming === '') {
            return false;
        }

        return hash_equals($expected, $incoming);
    }

}
