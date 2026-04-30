<?php

namespace Database\Factories;

use App\Models\ContactPhone;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContactPhoneFactory extends Factory
{
    protected $model = ContactPhone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'contact_id' => null,
            'phone' => '+' . fake()->countryCode() . fake()->numberBetween(1000000, 999999999),
            'type' => fake()->randomElement(['mobile', 'work', 'home', 'whatsapp']),
            'is_primary' => fake()->boolean(15),
            'sort' => fake()->numberBetween(1, 500),
        ];
    }
}

