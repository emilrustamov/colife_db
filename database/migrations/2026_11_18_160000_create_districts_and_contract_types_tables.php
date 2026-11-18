<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255)->unique();
            $table->unsignedInteger('sort')->default(500);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
        });

        Schema::create('contract_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bitrix_enum_id')->nullable()->unique();
            $table->string('name', 255)->unique();
            $table->string('bucket', 50)->nullable()->index();
            $table->unsignedInteger('sort')->default(500);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('apartments', function (Blueprint $table): void {
            $table->foreignId('district_id')
                ->nullable()
                ->after('district')
                ->constrained('districts')
                ->nullOnDelete();
        });

        $this->seedContractTypes();
        $this->backfillDistricts();
    }

    public function down(): void
    {
        Schema::table('apartments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('district_id');
        });
        Schema::dropIfExists('contract_types');
        Schema::dropIfExists('districts');
    }

    private function seedContractTypes(): void
    {
        $now = now();
        $rows = [
            ['bitrix_enum_id' => 882, 'name' => 'Airbnb', 'bucket' => 'краткосрок', 'sort' => 10],
            ['bitrix_enum_id' => 1304, 'name' => 'Booking', 'bucket' => 'краткосрок', 'sort' => 20],
            ['bitrix_enum_id' => 6578, 'name' => 'Short term (less than a month)', 'bucket' => 'краткосрок', 'sort' => 30],
            ['bitrix_enum_id' => 884, 'name' => 'Long term (1 month)', 'bucket' => 'долгосрок', 'sort' => 40],
            ['bitrix_enum_id' => 886, 'name' => 'Long term (2+ months)', 'bucket' => 'долгосрок', 'sort' => 50],
            ['bitrix_enum_id' => 8672, 'name' => 'Ejari', 'bucket' => 'долгосрок', 'sort' => 60],
        ];

        foreach ($rows as $row) {
            DB::table('contract_types')->updateOrInsert(
                ['bitrix_enum_id' => $row['bitrix_enum_id']],
                [
                    'name' => $row['name'],
                    'bucket' => $row['bucket'],
                    'sort' => $row['sort'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        if (! Schema::hasTable('unit_stays')) {
            return;
        }

        $existing = DB::table('contract_types')->pluck('name')->all();
        $existingFlip = array_flip($existing);
        $fromStays = DB::table('unit_stays')
            ->whereNotNull('contract_type')
            ->where('contract_type', '!=', '')
            ->distinct()
            ->orderBy('contract_type')
            ->pluck('contract_type');

        $sort = 900;
        foreach ($fromStays as $name) {
            $name = trim((string) $name);
            if ($name === '' || isset($existingFlip[$name])) {
                continue;
            }
            DB::table('contract_types')->insert([
                'bitrix_enum_id' => ctype_digit($name) ? (int) $name : null,
                'name' => $name,
                'bucket' => null,
                'sort' => $sort++,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $existingFlip[$name] = true;
        }
    }

    private function backfillDistricts(): void
    {
        if (! Schema::hasColumn('apartments', 'district')) {
            return;
        }

        $now = now();
        $names = DB::table('apartments')
            ->whereNotNull('district')
            ->where('district', '!=', '')
            ->distinct()
            ->orderBy('district')
            ->pluck('district');

        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            DB::table('districts')->updateOrInsert(
                ['name' => $name],
                [
                    'sort' => 500,
                    'is_deleted' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $map = DB::table('districts')->pluck('id', 'name');
        foreach ($map as $name => $id) {
            DB::table('apartments')
                ->where('district', $name)
                ->whereNull('district_id')
                ->update(['district_id' => $id]);
        }
    }
};
