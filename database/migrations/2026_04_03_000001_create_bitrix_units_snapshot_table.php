<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitrix_units_snapshot', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('unit_id')->unique();
            $table->unsignedBigInteger('apart_id')->nullable();

            $table->boolean('is_booked')->default(false);
            $table->boolean('is_moved_from_termination')->default(false);
            $table->boolean('is_stage_status')->default(false);
            $table->string('stage', 100);
            $table->boolean('is_sharing')->default(false);

            $table->dateTime('check_in_date')->nullable();

            $table->boolean('is_idle')->default(false);

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('apart_id');
            $table->index('stage');
            $table->index('is_booked');
            $table->index('is_sharing');
            $table->index('check_in_date');
            $table->index('is_idle');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitrix_units_snapshot');
    }
};
