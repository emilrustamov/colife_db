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
        Schema::table('unit_stays', function (Blueprint $table): void {
            $table->string('title', 255)->nullable()->after('bitrix_id');
            $table->unsignedBigInteger('stage_id')->nullable()->after('title');
            $table->string('type_of_deal', 100)->nullable()->after('contract_type');
            $table->string('type_of_payment', 100)->nullable()->after('type_of_deal');
            $table->date('contract_start_date')->nullable()->after('type_of_payment');
            $table->date('contract_end_date')->nullable()->after('contract_start_date');
            $table->unsignedSmallInteger('months_of_stay')->nullable()->after('contract_end_date');
            $table->decimal('rental_price', 12, 2)->nullable()->after('months_of_stay');
            $table->decimal('deposit', 12, 2)->nullable()->after('rental_price');
            $table->decimal('total_contract_amount', 12, 2)->nullable()->after('deposit');
            $table->decimal('opportunity', 12, 2)->nullable()->after('total_contract_amount');
            $table->string('currency_id', 10)->nullable()->after('opportunity');
            $table->string('passport_number', 100)->nullable()->after('currency_id');

            $table->index('stage_id');
            $table->index('type_of_deal');
            $table->index('contract_start_date');
            $table->index('contract_end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_stays', function (Blueprint $table): void {
            $table->dropIndex(['stage_id']);
            $table->dropIndex(['type_of_deal']);
            $table->dropIndex(['contract_start_date']);
            $table->dropIndex(['contract_end_date']);

            $table->dropColumn([
                'title',
                'stage_id',
                'type_of_deal',
                'type_of_payment',
                'contract_start_date',
                'contract_end_date',
                'months_of_stay',
                'rental_price',
                'deposit',
                'total_contract_amount',
                'opportunity',
                'currency_id',
                'passport_number',
            ]);
        });
    }
};
