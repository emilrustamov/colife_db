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
        if (Schema::hasColumn('landlord_finance_sheets', 'is_bitrix_fail')) {
            DB::table('landlord_finance_sheets')
                ->where('is_bitrix_fail', true)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);

            Schema::table('landlord_finance_sheets', function (Blueprint $table) {
                $table->dropColumn('is_bitrix_fail');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('landlord_finance_sheets', function (Blueprint $table) {
            $table->boolean('is_bitrix_fail')->default(false)->after('is_active');
        });
    }
};
