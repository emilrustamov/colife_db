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
        if (Schema::hasTable('landlord_finance_sheets')) {
            return;
        }

        Schema::create('landlord_finance_sheets', function (Blueprint $table) {
            $table->id();
            $table->string('spreadsheet_id', 128);
            $table->string('landlord_id', 64);
            $table->string('unit_id', 64);
            $table->string('title')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['spreadsheet_id', 'landlord_id', 'unit_id'], 'lfs_sheet_landlord_unit_uq');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landlord_finance_sheets');
    }
};
