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
        if (Schema::hasTable('disk_synced_files')) {
            return;
        }

        Schema::create('disk_synced_files', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('list_id');
            $table->unsignedBigInteger('element_bitrix_id');
            $table->unsignedBigInteger('folder_bitrix_id')->nullable();
            $table->string('folder_name', 512)->nullable();
            $table->string('field_code', 128);
            $table->unsignedBigInteger('bitrix_file_id');
            $table->unsignedInteger('content_version')->default(0);
            $table->string('original_name', 512)->nullable();
            $table->string('local_path', 1024);
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['list_id', 'element_bitrix_id', 'field_code', 'bitrix_file_id'],
                'disk_synced_files_unique'
            );
            $table->index(['list_id', 'element_bitrix_id']);
            $table->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disk_synced_files');
    }
};
