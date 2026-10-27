<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateExternalIdRequest;
use App\Services\AlmaContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AlmaContactController extends Controller
{
    /**
     * Update Alma contact external_id by email from local cache.
     */
    public function updateExternalId(
        UpdateExternalIdRequest $request,
        AlmaContactService $contactService
    ): JsonResponse {
        $data = $request->validated();
        $email = (string) $data['email'];
        $externalId = (string) $data['external_id'];
        $logger = Log::channel('alma_contacts');

        try {
            $result = $contactService->updateExternalIdByEmail($email, $externalId);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $status = $message === 'Alma contact not found for email.' ? 404 : 422;
            $logger->warning('Alma external-id API rejected', [
                'email' => $email,
                'external_id' => $externalId,
                'status' => $status,
                'message' => $message,
            ]);

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        } catch (Throwable $e) {
            $logger->error('Alma external-id API failed', [
                'email' => $email,
                'external_id' => $externalId,
                'status' => 502,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Alma API request failed.',
            ], 502);
        }

        $contact = $result['contact'];
        $logger->info('Alma external-id API ok', [
            'email' => $contact->email,
            'external_id' => $externalId,
            'alma_id' => $contact->alma_id,
            'bitrix_id' => $contact->bitrix_id,
            'changed' => $result['changed'],
        ]);

        return response()->json([
            'success' => true,
            'changed' => $result['changed'],
            'message' => $result['changed']
                ? 'external_id updated successfully.'
                : 'external_id already up to date.',
            'data' => [
                'alma_id' => $contact->alma_id,
                'bitrix_id' => $contact->bitrix_id,
                'email' => $contact->email,
            ],
        ]);
    }
}
