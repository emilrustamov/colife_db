<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncBitrixDiskListJob;
use App\Services\BitrixDiskSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class DiskSyncController extends Controller
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

        SyncBitrixDiskListJob::dispatch($listId, $elementId);

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
    public function pull(Request $request, BitrixDiskSyncService $service): JsonResponse
    {
        if (! $this->isPullAuthorized($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $elementId = $this->resolveElementId($request);
        if ($elementId === null) {
            return response()->json([
                'success' => false,
                'message' => 'element_id is required.',
            ], 422);
        }

        $listId = $this->resolveListId($request);

        try {
            @set_time_limit(180);
            sleep(5);
            $result = $service->sync($listId, $elementId);

            if ((int) ($result['downloaded'] ?? 0) === 0) {
                sleep(5);
                $retry = $service->sync($listId, $elementId);
                $result['downloaded'] = (int) $result['downloaded'] + (int) ($retry['downloaded'] ?? 0);
                $result['unchanged'] = (int) ($retry['unchanged'] ?? $result['unchanged']);
                $result['marked_deleted'] = (int) $result['marked_deleted'] + (int) ($retry['marked_deleted'] ?? 0);
                $result['failed'] = (int) ($retry['failed'] ?? 0);
            }
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

        SyncBitrixDiskListJob::dispatch($listId, $elementId)->delay(now()->addSeconds(15));

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
     * Authorize Bitrix URL calls via X-Api-Key or key query/body param.
     */
    private function isPullAuthorized(Request $request): bool
    {
        $expected = (string) config('services.client_balance.api_key', '');
        if ($expected === '') {
            return false;
        }

        $provided = (string) (
            $request->header('X-Api-Key')
            ?: $request->input('key')
            ?: $request->input('api_key')
            ?: ''
        );

        return hash_equals($expected, $provided);
    }

    /**
     * Resolve list iblock id from request or config default.
     */
    private function resolveListId(Request $request): int
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
    private function resolveElementId(Request $request): ?int
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
