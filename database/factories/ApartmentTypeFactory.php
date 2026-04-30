<?php

namespace Database\Factories;

use App\Models\ApartmentType;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApartmentTypeFactory extends Factory
{
    protected $model = ApartmentType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bitrix_enum_id' => fake()->unique()->numberBetween(100000000, 999999999),
            'code' => fake()->unique()->lexify('APT????'),
            'name' => fake()->words(2, true),
            'sort' => fake()->numberBetween(100, 900),
        ];
    }
}

