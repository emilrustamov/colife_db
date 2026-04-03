<?php

namespace Database\Factories;

use App\Models\Apartment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ApartmentFactory extends Factory
{
    protected $model = Apartment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $statuses = ['free', 'busy'];
        $propertyModes = ['sharing', 'unit'];
        $rentalTypes = ['holiday_home', 'ejari', 'holiday_home_ejari', 'hotel_apartment'];
        $transportTypes = ['metro', 'tram', 'bus'];

        return [
            'id' => Str::uuid()->toString(),
            'bitrix_id' => fake()->unique()->numberBetween(100000000, 999999999),
            'title' => fake()->optional(0.6)->words(3, true),
            'stage_id' => null,
            'building_id' => null,
            'landlord_contact_id' => null,
            'metro_station_id' => null,
            'apartment_type_id' => null,
            'internal_number' => fake()->boolean(60) ? 'INT-' . fake()->numberBetween(1, 99999) : null,
            'address' => fake()->boolean(70) ? fake()->address() : null,
            'property_mode' => fake()->boolean(80) ? fake()->randomElement($propertyModes) : null,
            'rental_type' => fake()->boolean(60) ? fake()->randomElement($rentalTypes) : null,
            'status' => fake()->randomElement($statuses),
            'busy_reason' => fake()->boolean(10) ? fake()->sentence(3) : null,
            'work_model' => fake()->boolean(30) ? fake()->randomElement(['owner', 'shared', 'agency']) : null,
            'floor' => fake()->boolean(70) ? fake()->numberBetween(0, 40) : null,
            'metro_minutes' => fake()->boolean(60) ? fake()->numberBetween(1, 60) : null,
            'transport_type' => fake()->boolean(60) ? fake()->randomElement($transportTypes) : null,
            'parking_number' => fake()->boolean(20) ? 'P-' . fake()->numberBetween(1, 999) : null,
            'google_maps_link' => fake()->boolean(20) ? 'https://maps.google.com/?q=' . urlencode(fake()->address()) : null,
            'bathrooms' => fake()->boolean(80) ? fake()->numberBetween(1, 3) : null,
            'rooms' => fake()->boolean(80) ? fake()->numberBetween(1, 4) : null,
            'area_sqm' => fake()->boolean(80) ? fake()->randomFloat(2, 18, 180) : null,
            'wifi_name' => fake()->boolean(25) ? fake()->lexify('WIFI-????') : null,
            'wifi_password' => fake()->boolean(25) ? Str::random(10) : null,
            'access_cards' => fake()->boolean(20) ? fake()->numberBetween(1, 10) : null,
            'parking_cards' => fake()->boolean(20) ? fake()->numberBetween(1, 10) : null,
            'keys_count' => fake()->boolean(20) ? fake()->numberBetween(1, 10) : null,
            'lock_pass' => fake()->boolean(15) ? fake()->numerify('##-####') : null,
            'keybox_code' => fake()->boolean(15) ? fake()->numerify('####-###') : null,
            'room_keys_notes' => fake()->boolean(10) ? fake()->sentence(6) : null,
            'is_deleted' => fake()->boolean(5),
            'bitrix_created_at' => fake()->boolean(70) ? fake()->dateTimeBetween('-90 days', '-1 day') : null,
            'bitrix_updated_at' => fake()->boolean(70) ? fake()->dateTimeBetween('-60 days', 'now') : null,
            'last_synced_at' => fake()->boolean(60) ? fake()->dateTimeBetween('-30 days', 'now') : null,
        ];
    }
}

