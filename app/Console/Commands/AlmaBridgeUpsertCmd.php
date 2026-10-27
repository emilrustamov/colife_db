<?php

namespace App\Console\Commands;

use App\Services\AlmaBridge\ApartmentService;
use App\Services\AlmaBridge\BlockingService;
use App\Services\AlmaBridge\BridgeSupport;
use App\Services\AlmaBridge\LandlordContractService;
use App\Services\AlmaBridge\TenantContractService;
use App\Services\AlmaBridge\TerminationService;
use App\Services\AlmaBridge\UnitService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class AlmaBridgeUpsertCmd extends Command
{
    protected $signature = 'alma-bridge:upsert
        {type : apartment|unit|contract|landlord|blocking|termination}
        {id : Bitrix entity or list element id}
        {--project=Dubai : Dubai or HongKong}
        {--create : Force create for contract/landlord}
        {--update : Force update for contract/landlord}';

    protected $description = 'Manually upsert one Bitrix entity into Alma via Alma Bridge';

    /**
     * Run manual Alma Bridge upsert.
     */
    public function handle(
        ApartmentService $apartments,
        UnitService $units,
        TenantContractService $tenantContracts,
        LandlordContractService $landlordContracts,
        BlockingService $blockings,
        TerminationService $terminations,
    ): int {
        if (! BridgeSupport::enabled()) {
            $this->warn('ALMA_BRIDGE_ENABLED=false — upsert will no-op / skip writes.');
        }

        $type = (string) $this->argument('type');
        $id = (string) $this->argument('id');
        $project = (string) $this->option('project');

        try {
            $result = match ($type) {
                'apartment' => $apartments->upsert($id, $project),
                'unit' => $units->upsert($id, $project),
                'contract' => $this->runTenant($tenantContracts, $id, $project),
                'landlord' => $this->runLandlord($landlordContracts, $id, $project),
                'blocking' => $blockings->apply($id, $project),
                'termination' => $terminations->apply($id, $project),
                default => throw new InvalidArgumentException('Unknown type: '.$type),
            };
            $this->info(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runTenant(TenantContractService $contracts, string $id, string $project): array
    {
        if ($this->option('create')) {
            return $contracts->create($id, $project);
        }
        if ($this->option('update')) {
            return $contracts->update($id, $project);
        }

        return $contracts->upsert($id, $project);
    }

    /**
     * @return array<string, mixed>
     */
    private function runLandlord(LandlordContractService $contracts, string $id, string $project): array
    {
        if ($this->option('create')) {
            return $contracts->create($id, $project);
        }
        if ($this->option('update')) {
            return $contracts->update($id, $project);
        }

        return $contracts->upsert($id, $project);
    }
}
