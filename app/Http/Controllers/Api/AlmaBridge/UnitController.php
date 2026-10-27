<?php

namespace App\Http\Controllers\Api\AlmaBridge;

use App\Http\Controllers\Api\AlmaBridge\Concerns\RespondsWithBridgeResult;
use App\Http\Controllers\Controller;
use App\Services\AlmaBridge\UnitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    use RespondsWithBridgeResult;

    /**
     * Upsert Bitrix unit/room into Alma.
     */
    public function upsert(Request $request, UnitService $units): JsonResponse
    {
        return $this->runBridge($request, fn (string $id, string $project) => $units->upsert($id, $project));
    }
}
