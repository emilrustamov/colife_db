<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAlmaContactExternalIdRequest;
use App\Services\AlmaContactService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class AlmaContactController extends Controller
{
    /**
     * Update Alma contact external_id by email from local cache.
     */
    public function updateExternalId(
        UpdateAlmaContactExternalIdRequest $request,
        AlmaContactService $contactService
    ): JsonResponse {
        $data = $request->validated();

        try {
            $result = $contactService->updateExternalIdByEmail(
                (string) $data['email'],
                (string) $data['external_id']
            );
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $status = $message === 'Alma contact not found for email.' ? 404 : 422;

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Alma API request failed.',
            ], 502);
        }

        $contact = $result['contact'];

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
