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
        if (! Schema::hasTable('disk_synced_files') || Schema::hasColumn('disk_synced_files', 'folder_url')) {
            return;
        }

        Schema::table('disk_synced_files', function (Blueprint $table): void {
            $table->string('folder_url', 1024)->nullable()->after('folder_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('disk_synced_files') || ! Schema::hasColumn('disk_synced_files', 'folder_url')) {
            return;
        }

        Schema::table('disk_synced_files', function (Blueprint $table): void {
            $table->dropColumn('folder_url');
        });
    }
};
