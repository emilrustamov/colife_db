<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CleaningSubmitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleaningSubmitController extends Controller
{
    public function __construct(
        private readonly CleaningSubmitService $submitService
    ) {}

    /**
     * Accept cleaning report form payload and create Bitrix task(s) + unit comment.
     */
    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'apartment_id' => ['required', 'integer', 'min:1'],
            'apartment_title' => ['required', 'string', 'max:255'],
            'unit_id' => ['required', 'integer', 'min:1'],
            'unit_title' => ['required', 'string', 'max:255'],
            'cleaning_type' => ['required', 'string', 'max:100'],
            'date' => ['required', 'string', 'max:32'],
            'time' => ['required', 'string', 'max:16'],
            'staff' => ['required', 'string', 'max:100'],
            'checklist' => ['nullable', 'array'],
            'checklist.*' => ['string', 'max:100'],
            'checklist_other' => ['nullable', 'string', 'max:255'],
            'needs_repair' => ['nullable'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['file', 'image', 'max:10240'],
        ]);

        $staffList = config('services.cleaning.staff', []);
        if (! is_array($staffList) || ! in_array($validated['staff'], $staffList, true)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid cleaning staff.'], 422);
        }

        $payload = [
            'apartment_id' => (int) $validated['apartment_id'],
            'apartment_title' => (string) $validated['apartment_title'],
            'unit_id' => (int) $validated['unit_id'],
            'unit_title' => (string) $validated['unit_title'],
            'cleaning_type' => (string) $validated['cleaning_type'],
            'date' => (string) $validated['date'],
            'time' => (string) $validated['time'],
            'staff' => (string) $validated['staff'],
            'checklist' => array_values($validated['checklist'] ?? []),
            'checklist_other' => (string) ($validated['checklist_other'] ?? ''),
            'needs_repair' => $request->boolean('needs_repair'),
            'notes' => (string) ($validated['notes'] ?? ''),
        ];

        $photos = $request->file('photos', []);
        if (! is_array($photos)) {
            $photos = $photos ? [$photos] : [];
        }

        try {
            $result = $this->submitService->submit($payload, $photos);

            return response()->json([
                'status' => 'ok',
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            Log::channel('bitrix_cleaning')->error('CLEANING_SUBMIT_FAILED', [
                'message' => $e->getMessage(),
                'apartment_id' => $payload['apartment_id'],
                'unit_id' => $payload['unit_id'],
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
