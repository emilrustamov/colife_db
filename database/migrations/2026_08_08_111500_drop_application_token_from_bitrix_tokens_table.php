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
        Schema::table('bitrix_tokens', function (Blueprint $table): void {
            if (Schema::hasColumn('bitrix_tokens', 'application_token')) {
                $table->dropColumn('application_token');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bitrix_tokens', function (Blueprint $table): void {
            if (! Schema::hasColumn('bitrix_tokens', 'application_token')) {
                $table->string('application_token', 64)->nullable()->after('member_id');
            }
        });
    }
};
