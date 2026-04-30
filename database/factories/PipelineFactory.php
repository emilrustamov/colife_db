<?php

namespace Database\Factories;

use App\Models\Pipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

class PipelineFactory extends Factory
{
    protected $model = Pipeline::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity_type' => 'unit',
            'bitrix_id' => fake()->unique()->numberBetween(100000000, 999999999),
            'name' => fake()->words(3, true),
            'sort' => fake()->numberBetween(100, 900),
            'bitrix_created_at' => fake()->boolean(70) ? fake()->dateTimeBetween('-90 days', '-1 day') : null,
            'bitrix_updated_at' => fake()->boolean(70) ? fake()->dateTimeBetween('-60 days', 'now') : null,
            'last_synced_at' => fake()->boolean(60) ? fake()->dateTimeBetween('-30 days', 'now') : null,
            'is_deleted' => fake()->boolean(5),
        ];
    }
}

