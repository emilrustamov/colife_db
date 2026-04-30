<?php

namespace Database\Factories;

use App\Models\MetroStation;
use Illuminate\Database\Eloquent\Factories\Factory;

class MetroStationFactory extends Factory
{
    protected $model = MetroStation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bitrix_id' => fake()->unique()->numberBetween(100000000, 999999999),
            'name' => fake()->streetName . ' ' . fake()->numberBetween(1, 99),
        ];
    }
}

