<?php

namespace App\Http\Controllers\Api\AlmaBridge;

use App\Http\Controllers\Api\AlmaBridge\Concerns\RespondsWithBridgeResult;
use App\Http\Controllers\Controller;
use App\Services\AlmaBridge\TerminationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TerminationController extends Controller
{
    use RespondsWithBridgeResult;

    /**
     * Apply termination list element as Alma synthetic tenant contract.
     */
    public function apply(Request $request, TerminationService $terminations): JsonResponse
    {
        return $this->runBridge($request, fn (string $id, string $project) => $terminations->apply($id, $project));
    }
}
