<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UnitFactory extends Factory
{
    protected $model = Unit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'bitrix_id' => fake()->unique()->numberBetween(100000000, 999999999),
            'apartment_id' => null,
            'title' => fake()->optional(0.6)->words(2, true),
            'stage_id' => null,
            'internal_number' => fake()->boolean(60) ? 'U' . fake()->numberBetween(1, 999999) : null,
            'is_deleted' => fake()->boolean(5),
            'bitrix_created_at' => fake()->boolean(70) ? fake()->dateTimeBetween('-90 days', '-1 day') : null,
            'bitrix_updated_at' => fake()->boolean(70) ? fake()->dateTimeBetween('-60 days', 'now') : null,
            'last_synced_at' => fake()->boolean(60) ? fake()->dateTimeBetween('-30 days', 'now') : null,
        ];
    }
}

