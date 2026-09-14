<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $aePortalId = DB::table('portals')->where('code', 'ae')->value('id');
        if ($aePortalId === null) {
            throw new RuntimeException('Portal ae is required before adding portal_id to apartments/units.');
        }

        Schema::table('apartments', function (Blueprint $table): void {
            $table->foreignId('portal_id')->nullable()->after('id')->constrained('portals')->restrictOnDelete();
        });

        Schema::table('units', function (Blueprint $table): void {
            $table->foreignId('portal_id')->nullable()->after('id')->constrained('portals')->restrictOnDelete();
        });

        DB::table('apartments')->whereNull('portal_id')->update(['portal_id' => $aePortalId]);
        DB::table('units')->whereNull('portal_id')->update(['portal_id' => $aePortalId]);

        Schema::table('apartments', function (Blueprint $table): void {
            $table->dropUnique(['bitrix_id']);
        });
        Schema::table('units', function (Blueprint $table): void {
            $table->dropUnique(['bitrix_id']);
        });

        DB::statement('ALTER TABLE apartments MODIFY portal_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE units MODIFY portal_id BIGINT UNSIGNED NOT NULL');

        Schema::table('apartments', function (Blueprint $table): void {
            $table->unique(['portal_id', 'bitrix_id']);
        });
        Schema::table('units', function (Blueprint $table): void {
            $table->unique(['portal_id', 'bitrix_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('apartments', function (Blueprint $table): void {
            $table->dropUnique(['portal_id', 'bitrix_id']);
            $table->dropConstrainedForeignId('portal_id');
            $table->unique('bitrix_id');
        });

        Schema::table('units', function (Blueprint $table): void {
            $table->dropUnique(['portal_id', 'bitrix_id']);
            $table->dropConstrainedForeignId('portal_id');
            $table->unique('bitrix_id');
        });
    }
};
