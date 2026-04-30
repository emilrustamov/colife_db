<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_balances')) {
            return;
        }

        Schema::table('client_balances', function (Blueprint $table) {
            $table->dropUnique('client_balances_unique_period');
            $table->unsignedBigInteger('apartment_id')->change();
            $table->unsignedBigInteger('client_id')->nullable()->change();
            $table->unique(['apartment_id', 'year', 'month'], 'client_balances_unique_period');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_balances')) {
            return;
        }

        Schema::table('client_balances', function (Blueprint $table) {
            $table->dropUnique('client_balances_unique_period');
            $table->unsignedBigInteger('apartment_id')->change();
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
            $table->unique(['client_id', 'apartment_id', 'year', 'month'], 'client_balances_unique_period');
        });
    }
};
