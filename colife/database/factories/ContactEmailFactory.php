<?php

namespace Database\Factories;

use App\Models\ContactEmail;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContactEmailFactory extends Factory
{
    protected $model = ContactEmail::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'contact_id' => null,
            'email' => fake()->unique()->safeEmail(),
            'type' => fake()->randomElement(['personal', 'work', 'other']),
            'is_primary' => fake()->boolean(15),
            'sort' => fake()->numberBetween(1, 500),
        ];
    }
}

