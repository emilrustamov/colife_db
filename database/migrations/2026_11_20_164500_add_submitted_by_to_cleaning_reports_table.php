<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_reports', function (Blueprint $table): void {
            $table->unsignedBigInteger('submitted_by_user_id')->nullable()->after('staff')->index();
            $table->string('submitted_by_name', 255)->nullable()->after('submitted_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_reports', function (Blueprint $table): void {
            $table->dropColumn(['submitted_by_user_id', 'submitted_by_name']);
        });
    }
};
