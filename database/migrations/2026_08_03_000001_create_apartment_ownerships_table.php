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
        Schema::create('apartment_ownerships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('bitrix_id')->nullable()->unique();
            $table->string('title', 255)->nullable();
            $table->unsignedBigInteger('stage_id')->nullable();
            $table->foreignUuid('apartment_id')->constrained('apartments')->cascadeOnDelete();
            $table->date('contract_start_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->date('pml_start_date')->nullable();
            $table->date('pml_end_date')->nullable();
            $table->date('dtcm_start_date')->nullable();
            $table->date('dtcm_end_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->string('termination_reason', 255)->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('bitrix_created_at')->nullable();
            $table->timestamp('bitrix_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('apartment_id');
            $table->index('stage_id');
            $table->index('contract_start_date');
            $table->index('contract_end_date');
            $table->index('termination_date');
            $table->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apartment_ownerships');
    }
};
