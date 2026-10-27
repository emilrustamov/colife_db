<?php

namespace App\Http\Controllers\Api\AlmaBridge;

use App\Http\Controllers\Api\AlmaBridge\Concerns\RespondsWithBridgeResult;
use App\Http\Controllers\Controller;
use App\Services\AlmaBridge\ApartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApartmentController extends Controller
{
    use RespondsWithBridgeResult;

    /**
     * Upsert apartment SPA into Alma unit.
     */
    public function upsert(Request $request, ApartmentService $apartments): JsonResponse
    {
        return $this->runBridge($request, fn (string $id, string $project) => $apartments->upsert($id, $project));
    }
}
