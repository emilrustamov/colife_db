<?php

namespace App\Http\Controllers\Api\AlmaBridge\Concerns;

use App\Services\AlmaBridge\BridgeSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

trait RespondsWithBridgeResult
{
    /**
     * Validate id/project and run Alma Bridge callback.
     *
     * @param  callable(string, string): array<string, mixed>  $callback
     */
    protected function runBridge(Request $request, callable $callback, ?string $bitrixId = null): JsonResponse
    {
        if (! BridgeSupport::enabled()) {
            return response()->json(['success' => false, 'message' => 'ALMA_BRIDGE_ENABLED=false'], 503);
        }

        if ($bitrixId !== null) {
            $request->merge(['id' => $bitrixId]);
        }

        $data = $request->validate([
            'id' => ['required'],
            'project' => ['nullable', 'string', Rule::in(['Dubai', 'HongKong'])],
        ]);
        $project = (string) ($data['project'] ?? config('alma_bridge.default_project', 'Dubai'));
        if (! in_array($project, ['Dubai', 'HongKong'], true)) {
            return response()->json(['success' => false, 'message' => 'Invalid project'], 422);
        }

        try {
            $result = $callback((string) $data['id'], $project);

            return response()->json(['success' => true, 'result' => $result]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
