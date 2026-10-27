<?php

namespace App\Http\Controllers\Api\AlmaBridge;

use App\Http\Controllers\Api\AlmaBridge\Concerns\RespondsWithBridgeResult;
use App\Http\Controllers\Controller;
use App\Services\AlmaBridge\TenantContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantContractController extends Controller
{
    use RespondsWithBridgeResult;

    /**
     * Create tenant contract in Alma from Bitrix id.
     */
    public function store(Request $request, TenantContractService $contracts): JsonResponse
    {
        return $this->runBridge($request, fn (string $id, string $project) => $contracts->create($id, $project));
    }

    /**
     * Update tenant contract in Alma from Bitrix id.
     */
    public function update(Request $request, TenantContractService $contracts, string $bitrixId): JsonResponse
    {
        return $this->runBridge(
            $request,
            fn (string $id, string $project) => $contracts->update($id, $project),
            $bitrixId
        );
    }

    /**
     * Upsert tenant contract (create / update / archive by stage).
     */
    public function upsert(Request $request, TenantContractService $contracts): JsonResponse
    {
        return $this->runBridge($request, fn (string $id, string $project) => $contracts->upsert($id, $project));
    }
}
