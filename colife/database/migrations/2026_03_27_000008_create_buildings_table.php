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
        Schema::create('buildings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('bitrix_id')->unique();
            $table->string('name', 255);
            $table->boolean('pool')->default(false);
            $table->boolean('jacuzzi')->default(false);
            $table->boolean('gym')->default(false);
            $table->boolean('sauna')->default(false);
            $table->boolean('parking')->default(false);
            $table->boolean('elevator')->default(false);
            $table->boolean('security')->default(false);
            $table->timestamp('bitrix_created_at')->nullable();
            $table->timestamp('bitrix_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('buildings');
    }
};
