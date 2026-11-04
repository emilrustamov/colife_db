<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CleaningSubmitService;
use App\Services\TokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleaningSubmitController extends Controller
{
    public function __construct(
        private readonly CleaningSubmitService $submitService,
        private readonly TokenVerifier $tokenVerifier,
    ) {}

    /**
     * Accept cleaning report form payload and create Bitrix task(s) + unit comment.
     */
    public function submit(Request $request): JsonResponse
    {
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        if ($contentLength > 0 && $request->request->count() === 0 && $request->files->count() === 0) {
            Log::channel('bitrix_cleaning')->warning('CLEANING_SUBMIT_TOO_LARGE', [
                'content_length' => $contentLength,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Upload is too large. Please add fewer photos.',
            ], 413);
        }

        if (! $this->tokenVerifier->authorizeCleaningForm($request)) {
            Log::channel('bitrix_cleaning')->warning('CLEANING_SUBMIT_REJECTED', [
                'domain' => $this->tokenVerifier->requestDomain($request),
                'content_length' => $contentLength,
            ]);

            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'apartment_id' => ['required', 'integer', 'min:1'],
            'apartment_title' => ['required', 'string', 'max:255'],
            'unit_id' => ['required', 'string', 'max:32'],
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
            'photos.*' => ['file', 'image', 'max:20480'],
            'receipts' => ['nullable', 'array', 'max:10'],
            'receipts.*' => ['file', 'image', 'max:20480'],
        ]);

        $isCommonArea = $validated['unit_id'] === 'common';
        if (! $isCommonArea && preg_match('/^[1-9]\d*$/', $validated['unit_id']) !== 1) {
            return response()->json(['status' => 'error', 'message' => 'Please select room / area.'], 422);
        }

        $staffList = config('services.cleaning.staff', []);
        if (! is_array($staffList) || ! in_array($validated['staff'], $staffList, true)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid cleaning staff.'], 422);
        }

        $payload = [
            'apartment_id' => (int) $validated['apartment_id'],
            'apartment_title' => (string) $validated['apartment_title'],
            'unit_id' => $isCommonArea ? 0 : (int) $validated['unit_id'],
            'unit_title' => $isCommonArea ? 'Common area' : (string) $validated['unit_title'],
            'is_common_area' => $isCommonArea,
            'cleaning_type' => (string) $validated['cleaning_type'],
            'date' => (string) $validated['date'],
            'time' => (string) $validated['time'],
            'staff' => (string) $validated['staff'],
            'checklist' => array_values($validated['checklist'] ?? []),
            'checklist_other' => (string) ($validated['checklist_other'] ?? ''),
            'needs_repair' => $request->boolean('needs_repair'),
            'notes' => (string) ($validated['notes'] ?? ''),
        ];

        $photos = $this->uploadedFiles($request, 'photos');
        $receipts = $this->uploadedFiles($request, 'receipts');
        $totalBytes = 0;
        foreach (array_merge($photos, $receipts) as $file) {
            $totalBytes += (int) $file->getSize();
        }
        if ($totalBytes > 50 * 1024 * 1024) {
            return response()->json([
                'status' => 'error',
                'message' => 'Total photo size must be 50 MB or less.',
            ], 422);
        }

        try {
            $result = $this->submitService->submit($payload, $photos, $receipts);

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

    /**
     * Normalize a multipart file field to a list.
     *
     * @return list<UploadedFile>
     */
    private function uploadedFiles(Request $request, string $key): array
    {
        $files = $request->file($key, []);
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        return is_array($files) ? array_values($files) : [];
    }
}
