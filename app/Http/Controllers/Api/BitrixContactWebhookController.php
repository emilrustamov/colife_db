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
        if (! $this->isValidToken($request)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $event = (string) $request->input('event', '');
        $bitrixId = (int) data_get($request->all(), 'data.FIELDS.ID', 0);

        if ($bitrixId <= 0) {
            return response()->json(['success' => false, 'message' => 'Missing contact ID'], 422);
        }

        if ($event === 'ONCRMCONTACTDELETE') {
            $updated = $syncService->markItemDeleted($bitrixId);
            Log::channel('bitrix_contacts')->info('Bitrix webhook contact delete handled', [
                'bitrix_id' => $bitrixId,
                'updated_rows' => $updated,
            ]);

            return response()->json(['success' => true, 'event' => $event, 'bitrix_id' => $bitrixId]);
        }

        if (! in_array($event, ['ONCRMCONTACTADD', 'ONCRMCONTACTUPDATE'], true)) {
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

        return response()->json(['success' => true, 'event' => $event, 'bitrix_id' => $bitrixId]);
    }

    /**
     * Validate webhook token.
     */
    private function isValidToken(Request $request): bool
    {
        $expected = (string) config('services.bitrix_contacts.event_token');
        if ($expected === '') {
            return false;
        }

        $incoming = (string) data_get($request->all(), 'auth.application_token', '');
        if ($incoming === '') {
            return false;
        }

        return hash_equals($expected, $incoming);
    }

}
