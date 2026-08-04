<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncDiskJob;
use App\Services\DiskSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class DiskApi extends Controller
{
    /**
     * Queue Bitrix list disk file sync.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'list_id' => ['required', 'integer', 'min:1'],
            'element_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $listId = (int) $validated['list_id'];
        $elementId = isset($validated['element_id']) ? (int) $validated['element_id'] : null;

        SyncDiskJob::dispatch($listId, $elementId);

        return response()->json([
            'success' => true,
            'queued' => true,
            'list_id' => $listId,
            'element_id' => $elementId,
        ]);
    }

    /**
     * Bitrix-friendly sync trigger: GET with element id in query.
     * Runs sync immediately (no queue).
     */
    public function pull(Request $request, DiskSync $service): JsonResponse
    {
        if (! $this->pullOk($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $elementId = $this->elementId($request);
        if ($elementId === null) {
            return response()->json([
                'success' => false,
                'message' => 'element_id is required.',
            ], 422);
        }

        $listId = $this->listId($request);

        try {
            @set_time_limit(180);
            $result = $service->sync($listId, $elementId);
        } catch (Throwable $e) {
            try {
                Log::channel('bitrix_disk')->error('Disk pull sync failed', [
                    'list_id' => $listId,
                    'element_id' => $elementId,
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable) {
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'list_id' => $listId,
                'element_id' => $elementId,
            ], 500);
        }

        SyncDiskJob::dispatch($listId, $elementId)->delay(now()->addSeconds(15));

        return response()->json([
            'success' => true,
            'queued' => false,
            'followup_queued_in' => 15,
            'list_id' => $listId,
            'element_id' => $elementId,
            'downloaded' => $result['downloaded'],
            'unchanged' => $result['unchanged'],
            'marked_deleted' => $result['marked_deleted'],
            'failed' => $result['failed'],
        ]);
    }

    /**
     * Authorize Bitrix URL calls via X-Api-Key header only.
     */
    private function pullOk(Request $request): bool
    {
        $expected = (string) config('services.client_balance.api_key', '');
        if ($expected === '') {
            return false;
        }

        $provided = (string) $request->header('X-Api-Key', '');
        if ($provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /**
     * Resolve list iblock id from request or config default.
     */
    private function listId(Request $request): int
    {
        foreach (['list_id', 'LIST_ID', 'iblock_id', 'IBLOCK_ID'] as $key) {
            $value = $request->input($key);
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        $configured = (int) config('services.bitrix.lists.disk_iblock_id', 322);

        return $configured > 0 ? $configured : 322;
    }

    /**
     * Resolve universal list element id from Bitrix-friendly payload shapes.
     */
    private function elementId(Request $request): ?int
    {
        foreach (['element_id', 'ELEMENT_ID', 'id', 'ID', 'elementId'] as $key) {
            $value = $request->input($key);
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        $documentId = $request->input('document_id');
        if (is_array($documentId) && $documentId !== []) {
            $last = end($documentId);
            if (is_numeric($last) && (int) $last > 0) {
                return (int) $last;
            }
        }

        if (is_string($documentId) && preg_match('/(\d+)\s*$/', $documentId, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
