<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Apartment;
use App\Models\ApartmentType;
use App\Models\BitrixUnitSnapshot;
use App\Models\Building;
use App\Models\Contact;
use App\Models\ContactEmail;
use App\Models\ContactPhone;
use App\Models\MetroStation;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\Unit;
use App\Models\UnitStay;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FakeDataSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the database with fake development data.
     */
    public function run(): void
    {
        if (! Schema::hasTable('buildings')) {
            return;
        }

        $buildingInsertCount = $this->targetInsertCount('buildings');
        if ($buildingInsertCount > 0) {
            Building::factory()->count($buildingInsertCount)->create();
        }
        $buildings = Building::query()->get(['id']);

        $metroInsertCount = $this->targetInsertCount('metro_stations');
        if ($metroInsertCount > 0 && Schema::hasTable('metro_stations')) {
            MetroStation::factory()->count($metroInsertCount)->create();
        }
        $metroStations = MetroStation::query()->get(['id']);

        $apartmentTypeInsertCount = $this->targetInsertCount('apartment_types');
        if ($apartmentTypeInsertCount > 0 && Schema::hasTable('apartment_types')) {
            ApartmentType::factory()->count($apartmentTypeInsertCount)->create();
        }
        $apartmentTypes = ApartmentType::query()->get(['id']);

        $contactInsertCount = $this->targetInsertCount('contacts');
        $contactTypeIds = Schema::hasTable('contact_types') ? DB::table('contact_types')->pluck('id')->all() : [];
        if (
            $contactInsertCount > 0
            && Schema::hasTable('contacts')
            && ! empty($contactTypeIds)
            && ! $buildings->isEmpty()
        ) {
            foreach (range(1, $contactInsertCount) as $i) {
                Contact::factory()->create([
                    'contact_type_id' => fake()->randomElement($contactTypeIds),
                ]);
            }
        }
        $contacts = Contact::query()->get(['id']);

        $pipelineInsertCount = $this->targetInsertCount('pipelines');
        if ($pipelineInsertCount > 0 && Schema::hasTable('pipelines')) {
            Pipeline::factory()->count($pipelineInsertCount)->create();
        }
        $pipelines = Pipeline::query()->get(['id', 'entity_type']);

        $stageInsertCount = $this->targetInsertCount('stages');
        if (
            $stageInsertCount > 0
            && Schema::hasTable('stages')
            && ! $pipelines->isEmpty()
        ) {
            foreach (range(1, $stageInsertCount) as $i) {
                $pipeline = $pipelines->random();
                Stage::factory()->create([
                    'pipeline_id' => $pipeline->id,
                    'entity_type' => $pipeline->entity_type,
                ]);
            }
        }
        $stages = Stage::query()->get(['id']);
        $stageIds = $stages->pluck('id')->all();

        $apartmentInsertCount = $this->targetInsertCount('apartments');
        if (
            $apartmentInsertCount > 0
            && ! $buildings->isEmpty()
            && ! $contacts->isEmpty()
        ) {
            foreach (range(1, $apartmentInsertCount) as $i) {
                Apartment::factory()->create([
                    'building_id' => $buildings->random()->id,
                    'landlord_contact_id' => $contacts->random()->id,
                    'metro_station_id' => $metroStations->isEmpty() ? null : $metroStations->random()->id,
                    'apartment_type_id' => $apartmentTypes->isEmpty() ? null : $apartmentTypes->random()->id,
                    'stage_id' => empty($stageIds) ? null : fake()->randomElement($stageIds),
                ]);
            }
        }
        $apartments = Apartment::query()->get(['id', 'bitrix_id', 'building_id']);

        $contactPhonesInsertCount = $this->targetInsertCount('contact_phones');
        if ($contactPhonesInsertCount > 0 && Schema::hasTable('contact_phones') && ! $contacts->isEmpty()) {
            foreach (range(1, $contactPhonesInsertCount) as $i) {
                ContactPhone::factory()->create([
                    'contact_id' => $contacts->random()->id,
                ]);
            }
        }

        $contactEmailsInsertCount = $this->targetInsertCount('contact_emails');
        if ($contactEmailsInsertCount > 0 && Schema::hasTable('contact_emails') && ! $contacts->isEmpty()) {
            foreach (range(1, $contactEmailsInsertCount) as $i) {
                ContactEmail::factory()->create([
                    'contact_id' => $contacts->random()->id,
                ]);
            }
        }

        $unitInsertCount = $this->targetInsertCount('units');
        if ($unitInsertCount > 0 && Schema::hasTable('units') && ! $apartments->isEmpty()) {
            foreach (range(1, $unitInsertCount) as $i) {
                Unit::factory()->create([
                    'apartment_id' => $apartments->random()->id,
                    'stage_id' => empty($stageIds) ? null : fake()->randomElement($stageIds),
                ]);
            }
        }
        $units = Unit::query()->get(['id', 'bitrix_id', 'apartment_id']);

        $unitStaysInsertCount = $this->targetInsertCount('unit_stays');
        if ($unitStaysInsertCount > 0 && Schema::hasTable('unit_stays') && ! $units->isEmpty() && ! $contacts->isEmpty()) {
            foreach (range(1, $unitStaysInsertCount) as $i) {
                $tenant = $contacts->random()->id;
                $coTenant = fake()->boolean(30) ? $contacts->random()->id : null;

                UnitStay::factory()->create([
                    'unit_id' => $units->random()->id,
                    'tenant_contact_id' => $tenant,
                    'co_tenant_contact_id' => $coTenant,
                ]);
            }
        }

        $snapshotInsertCount = $this->targetInsertCount('bitrix_units_snapshot');
        if ($snapshotInsertCount > 0 && Schema::hasTable('bitrix_units_snapshot') && ! $units->isEmpty() && ! $apartments->isEmpty()) {
            $unitBitrixIds = $units->pluck('bitrix_id')->all();
            $apartmentBitrixByUnitApartmentUuid = $apartments->pluck('bitrix_id', 'id')->all();

            $unitsToUse = collect($unitBitrixIds)->take(min($snapshotInsertCount, count($unitBitrixIds)))->all();
            foreach ($unitsToUse as $unitBitrixId) {
                $unit = $units->firstWhere('bitrix_id', $unitBitrixId);
                $apartBitrixId = $unit ? ($apartmentBitrixByUnitApartmentUuid[$unit->apartment_id] ?? null) : null;

                BitrixUnitSnapshot::factory()->create([
                    'unit_id' => (int) $unitBitrixId,
                    'apart_id' => $apartBitrixId !== null ? (int) $apartBitrixId : null,
                ]);
            }
        }

        $activityLogsInsertCount = $this->targetInsertCount('activity_logs');
        if ($activityLogsInsertCount > 0 && Schema::hasTable('activity_logs')) {
            $userIds = User::query()->pluck('id')->all();
            foreach (range(1, $activityLogsInsertCount) as $i) {
                $userId = empty($userIds) ? null : fake()->randomElement($userIds);
                ActivityLog::factory()->create([
                    'user_id' => $userId,
                ]);
            }
        }
    }

    private function targetInsertCount(string $table, int $min = 5, int $max = 10): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $existing = (int) DB::table($table)->count();
        if ($existing >= $max) {
            return 0;
        }

        $target = random_int($min, $max);

        return max(0, $target - $existing);
    }
}

