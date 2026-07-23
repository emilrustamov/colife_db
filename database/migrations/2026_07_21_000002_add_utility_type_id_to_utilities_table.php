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
        Schema::table('utilities', function (Blueprint $table): void {
            $table->unsignedBigInteger('utility_type_id')->nullable()->after('name');
            $table->index('utility_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('utilities', function (Blueprint $table): void {
            $table->dropIndex(['utility_type_id']);
            $table->dropColumn('utility_type_id');
        });
    }
};
