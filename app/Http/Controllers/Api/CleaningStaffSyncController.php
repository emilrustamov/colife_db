<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CleaningStaffSync;
use App\Services\TokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleaningStaffSyncController extends Controller
{
    public function __construct(
        private readonly CleaningStaffSync $staffSync,
        private readonly TokenVerifier $tokenVerifier,
    ) {}

    /**
     * Accept staff list elements collected via BX24 (user scope) and upsert locally.
     */
    public function sync(Request $request): JsonResponse
    {
        if (! $this->tokenVerifier->authorizeCleaningForm($request)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'elements' => ['required', 'array', 'max:500'],
            'elements.*.id' => ['required', 'integer', 'min:1'],
            'elements.*.name' => ['required', 'string', 'max:255'],
            'elements.*.sort' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        try {
            $result = $this->staffSync->applyElements($validated['elements']);

            return response()->json([
                'status' => 'ok',
                'result' => $result,
                'staff' => $result['names'],
            ]);
        } catch (Throwable $e) {
            Log::channel('bitrix_cleaning')->error('CLEANING_STAFF_CLIENT_SYNC_FAILED', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to sync cleaning staff.',
            ], 500);
        }
    }
}
