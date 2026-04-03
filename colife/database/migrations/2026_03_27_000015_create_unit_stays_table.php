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
        Schema::create('unit_stays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('bitrix_id')->nullable()->unique();
            $table->foreignUuid('unit_id')->constrained('units')->cascadeOnDelete();
            $table->foreignUuid('tenant_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUuid('co_tenant_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->unsignedBigInteger('deal_id')->nullable();
            $table->string('contract_type', 100)->nullable();
            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('bitrix_created_at')->nullable();
            $table->timestamp('bitrix_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('unit_id');
            $table->index('tenant_contact_id');
            $table->index('co_tenant_contact_id');
            $table->index('deal_id');
            $table->index('check_in_date');
            $table->index('check_out_date');
            $table->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_stays');
    }
};
