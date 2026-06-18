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
            if (! Schema::hasColumn('bitrix_tokens', 'refresh_token')) {
                $table->text('refresh_token')->nullable()->after('refresh_id');
            }

            if (! Schema::hasColumn('bitrix_tokens', 'expires_in')) {
                $table->unsignedInteger('expires_in')->nullable()->after('access_token');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bitrix_tokens', function (Blueprint $table): void {
            if (Schema::hasColumn('bitrix_tokens', 'refresh_token')) {
                $table->dropColumn('refresh_token');
            }

            if (Schema::hasColumn('bitrix_tokens', 'expires_in')) {
                $table->dropColumn('expires_in');
            }
        });
    }
};
