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
        if (! Schema::hasTable('landlord_finance_sheets')) {
            return;
        }

        Schema::table('landlord_finance_sheets', function (Blueprint $table) {
            $table->dropUnique('lfs_sheet_landlord_unit_uq');
        });

        DB::statement('ALTER TABLE landlord_finance_sheets MODIFY landlord_id VARCHAR(64) NULL');
        DB::statement('ALTER TABLE landlord_finance_sheets MODIFY unit_id VARCHAR(64) NULL');

        if (! Schema::hasColumn('landlord_finance_sheets', 'layout')) {
            Schema::table('landlord_finance_sheets', function (Blueprint $table) {
                $table->string('layout', 32)->default('auto')->after('title');
            });
        }

        Schema::table('landlord_finance_sheets', function (Blueprint $table) {
            $table->unique('spreadsheet_id', 'lfs_spreadsheet_id_uq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('landlord_finance_sheets')) {
            return;
        }

        Schema::table('landlord_finance_sheets', function (Blueprint $table) {
            $table->dropUnique('lfs_spreadsheet_id_uq');
        });

        if (Schema::hasColumn('landlord_finance_sheets', 'layout')) {
            Schema::table('landlord_finance_sheets', function (Blueprint $table) {
                $table->dropColumn('layout');
            });
        }

        DB::statement('ALTER TABLE landlord_finance_sheets MODIFY landlord_id VARCHAR(64) NOT NULL');
        DB::statement('ALTER TABLE landlord_finance_sheets MODIFY unit_id VARCHAR(64) NOT NULL');

        Schema::table('landlord_finance_sheets', function (Blueprint $table) {
            $table->unique(['spreadsheet_id', 'landlord_id', 'unit_id'], 'lfs_sheet_landlord_unit_uq');
        });
    }
};
