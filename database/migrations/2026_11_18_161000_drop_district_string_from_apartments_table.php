<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('apartments', 'district')) {
            return;
        }

        // Ensure FK is filled before dropping denormalized string.
        if (Schema::hasColumn('apartments', 'district_id') && Schema::hasTable('districts')) {
            $map = DB::table('districts')->pluck('id', 'name');
            foreach ($map as $name => $id) {
                DB::table('apartments')
                    ->where('district', $name)
                    ->whereNull('district_id')
                    ->update(['district_id' => $id]);
            }
        }

        Schema::table('apartments', function (Blueprint $table): void {
            $table->dropIndex(['district']);
            $table->dropColumn('district');
        });
    }

    public function down(): void
    {
        Schema::table('apartments', function (Blueprint $table): void {
            $table->string('district', 255)->nullable()->after('address');
            $table->index('district');
        });

        if (Schema::hasTable('districts') && Schema::hasColumn('apartments', 'district_id')) {
            DB::table('apartments as a')
                ->join('districts as d', 'd.id', '=', 'a.district_id')
                ->update(['a.district' => DB::raw('d.name')]);
        }
    }
};
