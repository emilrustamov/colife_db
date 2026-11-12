<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('landlord_finance_sheets', 'unit_id')) {
            return;
        }
        if (Schema::hasColumn('landlord_finance_sheets', 'apart_id')) {
            return;
        }

        Schema::table('landlord_finance_sheets', function ($table) {
            $table->renameColumn('unit_id', 'apart_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('landlord_finance_sheets', 'apart_id')) {
            return;
        }
        if (Schema::hasColumn('landlord_finance_sheets', 'unit_id')) {
            return;
        }

        Schema::table('landlord_finance_sheets', function ($table) {
            $table->renameColumn('apart_id', 'unit_id');
        });
    }
};
