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
        Schema::create('pipelines', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('bitrix_id')->unique();
            $table->string('name', 255);
            $table->unsignedInteger('sort')->default(500);
            $table->timestamp('bitrix_created_at')->nullable();
            $table->timestamp('bitrix_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index('entity_type');
            $table->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pipelines');
    }
};
