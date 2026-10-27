<?php

namespace App\Http\Controllers\Api\AlmaBridge;

use App\Http\Controllers\Api\AlmaBridge\Concerns\RespondsWithBridgeResult;
use App\Http\Controllers\Controller;
use App\Services\AlmaBridge\LandlordContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandlordContractController extends Controller
{
    use RespondsWithBridgeResult;

    /**
     * Create landlord contract in Alma from Bitrix id.
     */
    public function store(Request $request, LandlordContractService $contracts): JsonResponse
    {
        return $this->runBridge($request, fn (string $id, string $project) => $contracts->create($id, $project));
    }

    /**
     * Update landlord contract in Alma from Bitrix id.
     */
    public function update(Request $request, LandlordContractService $contracts, string $bitrixId): JsonResponse
    {
        return $this->runBridge(
            $request,
            fn (string $id, string $project) => $contracts->update($id, $project),
            $bitrixId
        );
    }
}
