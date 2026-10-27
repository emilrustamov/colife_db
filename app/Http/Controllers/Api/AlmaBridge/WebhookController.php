<?php

namespace App\Http\Controllers\Api\AlmaBridge;

use App\Enums\WebhookContext;
use App\Http\Controllers\Controller;
use App\Services\AlmaBridge\BridgeSupport;
use App\Services\AlmaBridge\ProjectResolver;
use App\Services\AlmaBridge\WebhookDispatcher;
use App\Services\TokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class WebhookController extends Controller
{
    public function __construct(
        private readonly TokenVerifier $tokenVerifier,
    ) {}

    /**
     * Handle Bitrix outgoing webhook for Alma Bridge.
     */
    public function __invoke(
        Request $request,
        ProjectResolver $projectResolver,
        WebhookDispatcher $dispatcher
    ): JsonResponse {
        if (! BridgeSupport::enabled()) {
            return response()->json([
                'success' => true,
                'skipped' => true,
                'reason' => 'ALMA_BRIDGE_ENABLED=false',
            ]);
        }

        $eventName = (string) $request->input('event', '');
        $dealAdd = (string) config('alma_bridge.events.deal_add');
        $dealUpdate = (string) config('alma_bridge.events.deal_update');
        if (in_array($eventName, [$dealAdd, $dealUpdate], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Deal events are not handled by Alma Bridge',
                'event' => $eventName,
            ], 422);
        }

        $id = $request->input('data.FIELDS.ID')
            ?? $request->input('data.ID')
            ?? $request->input('data.FIELDS.id');
        $entityTypeId = (int) ($request->input('data.FIELDS.ENTITY_TYPE_ID') ?? 0);

        $contactUpdate = (string) config('alma_bridge.events.contact_update');
        if ($eventName === $contactUpdate) {
            $entityTypeId = 3;
            $id = $request->input('data.FIELDS.ID') ?? $request->input('data.ID') ?? $id;
        }

        if ($id === null || $id === '') {
            BridgeSupport::log()->warning('Webhook: missing entity ID', [
                'event' => $eventName,
            ]);

            return response()->json(['success' => false, 'message' => 'missing entity ID'], 400);
        }

        try {
            $projectName = $projectResolver->fromRequest(
                $request,
                $entityTypeId > 0 ? $entityTypeId : null
            );
        } catch (InvalidArgumentException $e) {
            BridgeSupport::log()->warning('Alma bridge project resolve failed', [
                'error' => $e->getMessage(),
                'event' => $eventName,
            ]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if (! $this->webhookAuthorized($request, $projectName)) {
            BridgeSupport::log()->warning('Alma bridge webhook rejected by token', [
                'project' => $projectName,
            ]);

            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        try {
            $result = $dispatcher->dispatch($id, $entityTypeId > 0 ? $entityTypeId : null, $projectName, $eventName);

            return response()->json([
                'success' => true,
                'project' => $projectName,
                'result' => $result,
            ]);
        } catch (InvalidArgumentException $e) {
            BridgeSupport::log()->warning('Alma bridge unsupported entity', [
                'id' => $id,
                'entity_type_id' => $entityTypeId,
                'project' => $projectName,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            BridgeSupport::log()->error('Alma bridge webhook failed', [
                'id' => $id,
                'entity_type_id' => $entityTypeId,
                'project' => $projectName,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Per-project token only (Dubai ≠ HongKong). Also accepts that portal's app token.
     */
    private function webhookAuthorized(Request $request, string $projectName): bool
    {
        $incoming = $this->tokenVerifier->requestToken($request);
        if ($incoming === '') {
            return false;
        }

        $projectToken = trim((string) config('alma_bridge.projects.'.$projectName.'.webhook_token', ''));
        if ($projectToken !== '' && hash_equals($projectToken, $incoming)) {
            return true;
        }

        return $this->tokenVerifier->matchesDomainToken($request, WebhookContext::Bizproc);
    }
}
