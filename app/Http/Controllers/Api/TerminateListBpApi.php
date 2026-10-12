<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TerminateListWorkflows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TerminateListBpApi extends Controller
{
    /**
     * Bitrix-friendly trigger: stop all running BPs on a list element.
     */
    public function pull(Request $request, TerminateListWorkflows $service): JsonResponse
    {
        $elementId = $this->elementId($request);
        if ($elementId === null) {
            return response()->json([
                'success' => false,
                'message' => 'element_id is required.',
            ], 422);
        }

        $status = $request->input('status', $request->input('STATUS'));
        $statusText = is_string($status) ? $status : null;

        try {
            @set_time_limit(180);
            $result = $service->run($elementId, $statusText);
        } catch (Throwable $e) {
            Log::channel('bitrix_pauses')->error('List BP terminate failed', [
                'element_id' => $elementId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'element_id' => $elementId,
            ], 500);
        }

        Log::channel('bitrix_pauses')->info('List BP terminate completed', $result);

        return response()->json([
            'success' => $result['failed'] === 0 && $result['started_id'] !== null,
            'element_id' => $result['element_id'],
            'found' => $result['found'],
            'terminated' => $result['terminated'],
            'failed' => $result['failed'],
            'started_id' => $result['started_id'],
            'start_error' => $result['start_error'],
            'workflows' => $result['workflows'],
        ]);
    }

    /**
     * Resolve list element id from Bitrix-friendly payload shapes.
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
