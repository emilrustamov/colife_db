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
            if (! Schema::hasColumn('bitrix_tokens', 'member_id')) {
                $table->string('member_id')->nullable()->after('expires_at');
            }

            if (! Schema::hasColumn('bitrix_tokens', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('member_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bitrix_tokens', function (Blueprint $table): void {
            if (Schema::hasColumn('bitrix_tokens', 'user_id')) {
                $table->dropColumn('user_id');
            }

            if (Schema::hasColumn('bitrix_tokens', 'member_id')) {
                $table->dropColumn('member_id');
            }
        });
    }
};
