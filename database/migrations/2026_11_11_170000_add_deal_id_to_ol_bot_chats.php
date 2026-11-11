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
        if (! Schema::hasTable('ol_bot_chats')) {
            return;
        }
        Schema::table('ol_bot_chats', function (Blueprint $table) {
            if (! Schema::hasColumn('ol_bot_chats', 'deal_id')) {
                $table->string('deal_id', 64)->nullable()->after('dialog_id');
                $table->index('deal_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ol_bot_chats')) {
            return;
        }
        Schema::table('ol_bot_chats', function (Blueprint $table) {
            if (Schema::hasColumn('ol_bot_chats', 'deal_id')) {
                $table->dropIndex(['deal_id']);
                $table->dropColumn('deal_id');
            }
        });
    }
};
