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
        Schema::table('cleaning_reports', function (Blueprint $table): void {
            $table->unsignedBigInteger('unit_comment_id')->nullable()->after('apartment_comment_id');
            $table->index('unit_comment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cleaning_reports', function (Blueprint $table): void {
            $table->dropIndex(['unit_comment_id']);
            $table->dropColumn('unit_comment_id');
        });
    }
};
