<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_monthly_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('unit_bitrix_id', 100);
            $table->string('contract_type', 50);
            $table->string('month_key', 7);
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month_num');
            $table->unsignedInteger('occupied_days')->default(0);
            $table->decimal('total_revenue', 15, 2)->default(0);
            $table->decimal('avg_price_per_day', 15, 2)->default(0);
            $table->timestamp('calculated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['unit_bitrix_id', 'month_key', 'contract_type'], 'price_reports_unit_month_type_unique');
            $table->index(['year', 'month_num']);
            $table->index('unit_bitrix_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_monthly_reports');
    }
};
