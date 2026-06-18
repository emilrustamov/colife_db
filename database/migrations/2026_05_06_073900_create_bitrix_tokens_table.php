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
        if (Schema::hasTable('bitrix_tokens')) {
            return;
        }

        Schema::create('bitrix_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('domain')->unique();
            $table->text('auth_id')->nullable();
            $table->text('refresh_id')->nullable();
            $table->text('access_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bitrix_tokens');
    }
};
