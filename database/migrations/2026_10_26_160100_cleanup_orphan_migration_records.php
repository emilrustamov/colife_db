<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('migrations')->whereIn('migration', [
            '2026_04_03_200000_add_is_superadmin_to_users_table',
            '2026_04_03_200000_add_is_superadmin_to_users_table copy',
        ])->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
