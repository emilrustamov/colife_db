<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_balances')) {
            return;
        }

        Schema::create('client_balances', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('apartment_id');

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            $table->decimal('balance', 12, 2)->default(0);

            $table->timestamps();

            $table->unique(['apartment_id', 'year', 'month'], 'client_balances_unique_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_balances');
    }
};
