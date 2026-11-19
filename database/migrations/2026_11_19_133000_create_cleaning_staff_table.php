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
        Schema::create('cleaning_staff', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bitrix_id')->unique();
            $table->string('name', 255);
            $table->unsignedInteger('sort')->default(500);
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['is_deleted', 'sort', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cleaning_staff');
    }
};
