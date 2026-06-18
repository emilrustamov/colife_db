<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dialog_balances')) {
            return;
        }

        Schema::create('dialog_balances', function (Blueprint $table) {
            $table->id();
            $table->string('line_id');
            $table->string('line_name');
            $table->string('phone_number')->nullable();
            $table->integer('total_limit');
            $table->integer('used');
            $table->integer('remaining');
            $table->date('collected_at');
            $table->timestamps();

            $table->index(['line_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dialog_balances');
    }
};
