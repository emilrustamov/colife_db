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
        if (Schema::hasTable('bitrix_users')) {
            return;
        }

        Schema::create('bitrix_users', function (Blueprint $table): void {
            $table->id();
            $table->string('domain');
            $table->unsignedBigInteger('bitrix_user_id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->boolean('active')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['domain', 'bitrix_user_id'], 'bitrix_users_domain_user_unique');
            $table->index('domain', 'bitrix_users_domain_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bitrix_users');
    }
};
