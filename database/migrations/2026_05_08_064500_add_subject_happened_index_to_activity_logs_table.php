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
        if (! Schema::hasTable('activity_logs') || $this->indexExists()) {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->index(
                ['subject_type', 'subject_id', 'happened_at'],
                'activity_logs_subject_type_subject_id_happened_at_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('activity_logs') || ! $this->indexExists()) {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex('activity_logs_subject_type_subject_id_happened_at_idx');
        });
    }

    /**
     * Whether the composite subject/happened index already exists.
     */
    private function indexExists(): bool
    {
        $rows = DB::select(
            'SHOW INDEX FROM activity_logs WHERE Key_name = ?',
            ['activity_logs_subject_type_subject_id_happened_at_idx']
        );

        return $rows !== [];
    }
};
