<?php

namespace Database\Factories;

use App\Models\Stage;
use Illuminate\Database\Eloquent\Factories\Factory;

class StageFactory extends Factory
{
    protected $model = Stage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $isSuccess = fake()->boolean(60);
        $isFail = !$isSuccess && fake()->boolean(25);

        return [
            'entity_type' => 'unit',
            'pipeline_id' => null,
            'bitrix_stage_id' => fake()->unique()->lexify('STG????????'),
            'name' => fake()->words(2, true),
            'sort' => fake()->numberBetween(100, 900),
            'is_success' => $isSuccess,
            'is_fail' => $isFail,
            'is_deleted' => fake()->boolean(5),
            'bitrix_created_at' => fake()->boolean(70) ? fake()->dateTimeBetween('-90 days', '-1 day') : null,
            'bitrix_updated_at' => fake()->boolean(70) ? fake()->dateTimeBetween('-60 days', 'now') : null,
            'last_synced_at' => fake()->boolean(60) ? fake()->dateTimeBetween('-30 days', 'now') : null,
        ];
    }
}

