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
        Schema::create('utilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('bitrix_id')->unique();
            $table->string('name', 255)->nullable();
            $table->string('utility_type', 255)->nullable();
            $table->string('provider_company', 255)->nullable();
            $table->string('account_number', 255)->nullable();
            $table->string('login', 255)->nullable();
            $table->string('password', 255)->nullable();
            $table->string('email_for_registration', 255)->nullable();
            $table->string('name_used_for_registration', 255)->nullable();
            $table->foreignUuid('apartment_id')->nullable()->constrained('apartments')->nullOnDelete();
            $table->unsignedBigInteger('apartment_bitrix_id')->nullable();
            $table->unsignedBigInteger('acquisition_deal_id')->nullable();
            $table->timestamp('autopayment_date')->nullable();
            $table->string('apartment_text', 255)->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('bitrix_created_at')->nullable();
            $table->timestamp('bitrix_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('apartment_id');
            $table->index('apartment_bitrix_id');
            $table->index('acquisition_deal_id');
            $table->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('utilities');
    }
};
