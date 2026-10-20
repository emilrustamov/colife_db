<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('alma_contacts')) {
            return;
        }

        Schema::create('alma_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alma_id')->unique();
            $table->string('bitrix_id')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index('bitrix_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alma_contacts');
    }
};
