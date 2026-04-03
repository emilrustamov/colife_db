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
        Schema::create('apartments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('bitrix_id')->unique();
            $table->string('title', 255)->nullable();
            $table->unsignedBigInteger('stage_id')->nullable();
            $table->foreignUuid('building_id')->nullable()->constrained('buildings')->nullOnDelete();
            $table->foreignUuid('landlord_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('metro_station_id')->nullable()->constrained('metro_stations')->nullOnDelete();
            $table->foreignId('apartment_type_id')->nullable()->constrained('apartment_types')->nullOnDelete();
            $table->string('internal_number', 100)->nullable();
            $table->text('address')->nullable();
            $table->enum('property_mode', ['sharing', 'unit'])->nullable();
            $table->enum('rental_type', ['holiday_home', 'ejari', 'holiday_home_ejari', 'hotel_apartment'])->nullable();
            $table->enum('status', ['free', 'busy'])->default('free');
            $table->string('busy_reason', 255)->nullable();
            $table->string('work_model', 100)->nullable();
            $table->integer('floor')->nullable();
            $table->integer('metro_minutes')->nullable();
            $table->enum('transport_type', ['metro', 'tram', 'bus'])->nullable();
            $table->string('parking_number', 100)->nullable();
            $table->text('google_maps_link')->nullable();
            $table->integer('bathrooms')->nullable();
            $table->integer('rooms')->nullable();
            $table->decimal('area_sqm', 10, 2)->nullable();
            $table->string('wifi_name', 255)->nullable();
            $table->string('wifi_password', 255)->nullable();
            $table->integer('access_cards')->nullable();
            $table->integer('parking_cards')->nullable();
            $table->integer('keys_count')->nullable();
            $table->string('lock_pass', 255)->nullable();
            $table->string('keybox_code', 100)->nullable();
            $table->text('room_keys_notes')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('bitrix_created_at')->nullable();
            $table->timestamp('bitrix_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('building_id');
            $table->index('landlord_contact_id');
            $table->index('stage_id');
            $table->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apartments');
    }
};
