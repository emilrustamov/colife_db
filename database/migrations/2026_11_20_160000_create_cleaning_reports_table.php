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
        Schema::create('cleaning_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('apartment_bitrix_id')->index();
            $table->string('apartment_title', 255)->nullable();
            $table->unsignedBigInteger('unit_bitrix_id')->nullable()->index();
            $table->string('unit_title', 255)->nullable();
            $table->string('cleaning_type', 100)->nullable();
            $table->string('report_date', 32)->nullable();
            $table->string('report_time', 16)->nullable();
            $table->string('staff', 100)->nullable();
            $table->boolean('needs_repair')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('apartment_comment_id')->nullable()->unique();
            $table->unsignedBigInteger('repair_task_id')->nullable();
            $table->json('photo_previews')->nullable();
            $table->timestamp('reported_at')->nullable()->index();
            $table->timestamps();

            $table->index(['reported_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cleaning_reports');
    }
};
