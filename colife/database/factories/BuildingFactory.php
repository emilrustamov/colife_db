<?php

namespace Database\Factories;

use App\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BuildingFactory extends Factory
{
    protected $model = Building::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'bitrix_id' => fake()->unique()->numberBetween(100000000, 999999999),
            'name' => fake()->words(3, true),
            'pool' => fake()->boolean(30),
            'jacuzzi' => fake()->boolean(20),
            'gym' => fake()->boolean(25),
            'sauna' => fake()->boolean(15),
            'parking' => fake()->boolean(40),
            'elevator' => fake()->boolean(90),
            'security' => fake()->boolean(50),
            'bitrix_created_at' => fake()->boolean(70) ? fake()->dateTimeBetween('-90 days', '-1 day') : null,
            'bitrix_updated_at' => fake()->boolean(70) ? fake()->dateTimeBetween('-60 days', 'now') : null,
            'last_synced_at' => fake()->boolean(60) ? fake()->dateTimeBetween('-30 days', 'now') : null,
            'is_deleted' => fake()->boolean(5),
        ];
    }
}

