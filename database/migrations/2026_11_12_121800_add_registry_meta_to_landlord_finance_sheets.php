<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            if (! Schema::hasColumn('landlord_finance_sheets', 'landlord_id_2')) {
                $table->string('landlord_id_2', 64)->nullable()->after('landlord_id');
            }
            if (! Schema::hasColumn('landlord_finance_sheets', 'source_url')) {
                $table->string('source_url', 512)->nullable()->after('title');
            }
            if (! Schema::hasColumn('landlord_finance_sheets', 'is_external')) {
                $table->boolean('is_external')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('landlord_finance_sheets', 'id_source')) {
                $table->string('id_source', 32)->nullable()->after('is_external');
            }
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
            foreach (['landlord_id_2', 'source_url', 'is_external', 'id_source'] as $column) {
                if (Schema::hasColumn('landlord_finance_sheets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
