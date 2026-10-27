<?php

namespace App\Http\Controllers\Api\AlmaBridge;

use App\Http\Controllers\Api\AlmaBridge\Concerns\RespondsWithBridgeResult;
use App\Http\Controllers\Controller;
use App\Services\AlmaBridge\BlockingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlockingController extends Controller
{
    use RespondsWithBridgeResult;

    /**
     * Apply blocking list element as Alma synthetic tenant contract.
     */
    public function apply(Request $request, BlockingService $blockings): JsonResponse
    {
        return $this->runBridge($request, fn (string $id, string $project) => $blockings->apply($id, $project));
    }
}
