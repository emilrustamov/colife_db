<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CleaningReport;
use App\Services\BitrixOAuth;
use App\Services\BitrixRest;
use App\Services\CleaningPhotoCache;
use App\Services\TokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleaningHistoryController extends Controller
{
    public function __construct(
        private readonly TokenVerifier $tokenVerifier,
        private readonly BitrixRest $bitrixRest,
        private readonly BitrixOAuth $oauth,
        private readonly CleaningPhotoCache $photoCache,
    ) {}

    /**
     * Recent cleaning reports for the History tab (local cache).
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->tokenVerifier->cleaningFormUserId($request);
        if ($userId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $apartmentId = (int) $request->input('apartment_id', 0);
        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 20);
        $search = trim((string) $request->input('q', ''));

        $payload = CleaningReport::historyPayload(
            $apartmentId > 0 ? $apartmentId : null,
            $page,
            $perPage,
            $search
        );

        return response()->json([
            'status' => 'ok',
            'can_delete' => $this->canDeleteHistory($userId),
            ...$payload,
        ]);
    }

    /**
     * Delete local history + Bitrix apartment/unit comments, repair task and disk files.
     */
    public function destroy(Request $request): JsonResponse
    {
        $userId = $this->tokenVerifier->cleaningFormUserId($request);
        if ($userId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
        if (! $this->canDeleteHistory($userId)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $report = CleaningReport::query()->find((int) $validated['id']);
        if (! $report) {
            return response()->json(['status' => 'error', 'message' => 'Report not found'], 404);
        }

        $apartmentCommentId = (int) ($report->apartment_comment_id ?? 0);
        $unitCommentId = (int) ($report->unit_comment_id ?? 0);
        $apartmentId = (int) $report->apartment_bitrix_id;
        $unitId = (int) ($report->unit_bitrix_id ?? 0);
        $repairTaskId = (int) ($report->repair_task_id ?? 0);
        $fileIds = $this->photoFileIds($report->photo_previews);

        $apartmentCommentDeleted = false;
        if ($apartmentCommentId > 0 && $apartmentId > 0) {
            $apartmentCommentDeleted = $this->deleteBitrixComment(
                $apartmentCommentId,
                (int) config('services.cleaning.apartment_entity_type_id'),
                $apartmentId
            );
        }

        $unitCommentDeleted = false;
        if ($unitCommentId > 0 && $unitId > 0 && $unitCommentId !== $apartmentCommentId) {
            $unitCommentDeleted = $this->deleteBitrixComment(
                $unitCommentId,
                (int) config('services.cleaning.unit_entity_type_id'),
                $unitId
            );
        }

        $taskDeleted = false;
        if ($repairTaskId > 0) {
            $taskDeleted = $this->deleteRepairTask($repairTaskId);
        }

        $filesDeleted = 0;
        foreach ($fileIds as $fileId) {
            if ($this->deleteDiskFile($fileId)) {
                $filesDeleted++;
            }
        }
        $this->photoCache->forgetMany($fileIds);

        $report->delete();

        Log::channel('bitrix_cleaning')->info('CLEANING_HISTORY_DELETED', [
            'report_id' => (int) $validated['id'],
            'by_user' => $userId,
            'apartment_id' => $apartmentId,
            'unit_id' => $unitId > 0 ? $unitId : null,
            'apartment_comment_id' => $apartmentCommentId > 0 ? $apartmentCommentId : null,
            'unit_comment_id' => $unitCommentId > 0 ? $unitCommentId : null,
            'repair_task_id' => $repairTaskId > 0 ? $repairTaskId : null,
            'apartment_comment_deleted' => $apartmentCommentDeleted,
            'unit_comment_deleted' => $unitCommentDeleted,
            'task_deleted' => $taskDeleted,
            'files_deleted' => $filesDeleted,
            'files_total' => count($fileIds),
        ]);

        return response()->json([
            'status' => 'ok',
            'apartment_comment_deleted' => $apartmentCommentDeleted,
            'unit_comment_deleted' => $unitCommentDeleted,
            'task_deleted' => $taskDeleted,
            'files_deleted' => $filesDeleted,
        ]);
    }

    private function canDeleteHistory(int $userId): bool
    {
        $allowed = config('services.cleaning.history_delete_user_ids', []);
        if (! is_array($allowed)) {
            return false;
        }

        $ids = array_values(array_filter(array_map('intval', $allowed), static fn (int $id): bool => $id > 0));

        return in_array($userId, $ids, true);
    }

    /**
     * @param  mixed  $raw
     * @return list<int>
     */
    private function photoFileIds(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $photo) {
            if (! is_array($photo)) {
                continue;
            }
            $id = (int) ($photo['id'] ?? $photo['ID'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function deleteBitrixComment(int $commentId, int $ownerTypeId, int $ownerId): bool
    {
        $domain = $this->oauth->normalizeDomain((string) config('services.b24_tr.portal_domain'));
        if ($domain === '' || $ownerTypeId <= 0 || $ownerId <= 0) {
            return false;
        }

        try {
            $this->bitrixRest->postJson('crm.timeline.comment.delete', [
                'id' => $commentId,
                'ownerTypeId' => $ownerTypeId,
                'ownerId' => $ownerId,
            ], $domain);

            return true;
        } catch (Throwable $e) {
            Log::channel('bitrix_cleaning')->warning('CLEANING_HISTORY_COMMENT_DELETE_FAILED', [
                'comment_id' => $commentId,
                'owner_type_id' => $ownerTypeId,
                'owner_id' => $ownerId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function deleteRepairTask(int $taskId): bool
    {
        $domain = $this->oauth->normalizeDomain((string) config('services.b24_tr.portal_domain'));
        if ($domain === '' || $taskId <= 0) {
            return false;
        }

        try {
            $this->bitrixRest->postJson('tasks.task.delete', [
                'taskId' => $taskId,
                'id' => $taskId,
            ], $domain);

            return true;
        } catch (Throwable $e) {
            Log::channel('bitrix_cleaning')->warning('CLEANING_HISTORY_TASK_DELETE_FAILED', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function deleteDiskFile(int $fileId): bool
    {
        $domain = $this->oauth->normalizeDomain((string) config('services.b24_tr.portal_domain'));
        if ($domain === '' || $fileId <= 0) {
            return false;
        }

        try {
            $this->bitrixRest->postJson('disk.file.delete', [
                'id' => $fileId,
            ], $domain);

            return true;
        } catch (Throwable $e) {
            Log::channel('bitrix_cleaning')->warning('CLEANING_HISTORY_FILE_DELETE_FAILED', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
