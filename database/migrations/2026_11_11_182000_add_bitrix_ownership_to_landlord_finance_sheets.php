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
            $table->dropUnique('lfs_spreadsheet_id_uq');
        });

        if (! Schema::hasColumn('landlord_finance_sheets', 'sheet_name')) {
            Schema::table('landlord_finance_sheets', function (Blueprint $table) {
                $table->string('sheet_name', 255)->default('')->after('spreadsheet_id');
            });
        }

        if (! Schema::hasColumn('landlord_finance_sheets', 'bitrix_ownership_id')) {
            Schema::table('landlord_finance_sheets', function (Blueprint $table) {
                $table->unsignedBigInteger('bitrix_ownership_id')->nullable()->after('unit_id');
                $table->index('bitrix_ownership_id');
            });
        }

        DB::table('landlord_finance_sheets')->whereNull('sheet_name')->update(['sheet_name' => '']);

        Schema::table('landlord_finance_sheets', function (Blueprint $table) {
            $table->unique(['spreadsheet_id', 'sheet_name'], 'lfs_spreadsheet_sheet_uq');
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
            $table->dropUnique('lfs_spreadsheet_sheet_uq');
        });

        if (Schema::hasColumn('landlord_finance_sheets', 'bitrix_ownership_id')) {
            Schema::table('landlord_finance_sheets', function (Blueprint $table) {
                $table->dropIndex(['bitrix_ownership_id']);
                $table->dropColumn('bitrix_ownership_id');
            });
        }

        if (Schema::hasColumn('landlord_finance_sheets', 'sheet_name')) {
            Schema::table('landlord_finance_sheets', function (Blueprint $table) {
                $table->dropColumn('sheet_name');
            });
        }

        Schema::table('landlord_finance_sheets', function (Blueprint $table) {
            $table->unique('spreadsheet_id', 'lfs_spreadsheet_id_uq');
        });
    }
};
