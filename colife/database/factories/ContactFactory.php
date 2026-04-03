<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gender = fake()->randomElement(['male', 'female']);

        return [
            'id' => Str::uuid()->toString(),
            'bitrix_id' => fake()->unique()->numberBetween(100000000, 999999999),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'contact_type_id' => null,
            'nationality' => fake()->country(),
            'birth_date' => fake()->boolean(70) ? fake()->date('Y-m-d', now()->subYears(fake()->numberBetween(18, 70))) : null,
            'gender' => $gender,
            'language' => fake()->randomElement(['en', 'ru', 'ar', 'de']),
            'is_deleted' => fake()->boolean(5),
            'bitrix_created_at' => fake()->boolean(70) ? fake()->dateTimeBetween('-90 days', '-1 day') : null,
            'bitrix_updated_at' => fake()->boolean(70) ? fake()->dateTimeBetween('-60 days', 'now') : null,
            'last_synced_at' => fake()->boolean(60) ? fake()->dateTimeBetween('-30 days', 'now') : null,
        ];
    }
}

