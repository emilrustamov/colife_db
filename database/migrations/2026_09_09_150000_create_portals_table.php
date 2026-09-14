<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('portals', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->string('domain', 255)->unique();
            $table->string('timezone', 64)->nullable();
            $table->unsignedInteger('apartment_entity_type_id')->nullable();
            $table->unsignedInteger('unit_entity_type_id')->nullable();
            $table->string('apartment_units_field', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $rows = [];

        $aeDomain = trim((string) config('services.bitrix.portal_domain', ''));
        if ($aeDomain !== '') {
            $rows[] = [
                'code' => 'ae',
                'name' => 'UAE',
                'domain' => $aeDomain,
                'timezone' => (string) config('services.bitrix.portal_timezone', 'Europe/Moscow'),
                'apartment_entity_type_id' => 144,
                'unit_entity_type_id' => (int) config('services.bitrix.entity_type_id', 167),
                'apartment_units_field' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $hkDomain = trim((string) config('services.b24_hk.portal_domain', ''));
        if ($hkDomain !== '') {
            $rows[] = [
                'code' => 'hk',
                'name' => 'Hong Kong',
                'domain' => $hkDomain,
                'timezone' => null,
                'apartment_entity_type_id' => null,
                'unit_entity_type_id' => null,
                'apartment_units_field' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $trDomain = trim((string) config('services.b24_tr.portal_domain', ''));
        if ($trDomain !== '') {
            $rows[] = [
                'code' => 'tr',
                'name' => 'Turkey',
                'domain' => $trDomain,
                'timezone' => null,
                'apartment_entity_type_id' => (int) config('services.cleaning.apartment_entity_type_id', 165),
                'unit_entity_type_id' => (int) config('services.cleaning.unit_entity_type_id', 144),
                'apartment_units_field' => (string) config('services.cleaning.apartment_units_field', 'ufCrm5_1697012153'),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('portals')->insert($rows);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portals');
    }
};
