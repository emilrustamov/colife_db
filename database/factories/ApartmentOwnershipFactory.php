<?php

namespace Database\Factories;

use App\Models\ApartmentOwnership;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ApartmentOwnershipFactory extends Factory
{
    protected $model = ApartmentOwnership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-2 years', 'now');
        $end = fake()->dateTimeBetween($start->format('Y-m-d'), '+3 years');
        $terminationReasons = [
            null,
            'The apartment is sold',
            'The owner is dissatisfied with the performance',
            'For the owner\'s personal use',
            'Other',
        ];

        return [
            'id' => Str::uuid()->toString(),
            'bitrix_id' => null,
            'title' => fake()->boolean(70) ? 'Contract_landlord_'.fake()->bothify('AP ###') : null,
            'stage_id' => null,
            'apartment_id' => null,
            'contract_start_date' => $start->format('Y-m-d'),
            'contract_end_date' => $end->format('Y-m-d'),
            'pml_start_date' => fake()->boolean(50) ? $start->format('Y-m-d') : null,
            'pml_end_date' => fake()->boolean(50) ? $end->format('Y-m-d') : null,
            'dtcm_start_date' => fake()->boolean(50) ? $start->format('Y-m-d') : null,
            'dtcm_end_date' => fake()->boolean(50) ? $end->format('Y-m-d') : null,
            'termination_date' => fake()->boolean(15) ? fake()->dateTimeBetween($start, 'now')->format('Y-m-d') : null,
            'termination_reason' => fake()->randomElement($terminationReasons),
            'is_deleted' => fake()->boolean(5),
            'bitrix_created_at' => fake()->boolean(60) ? fake()->dateTimeBetween('-60 days', '-1 day') : null,
            'bitrix_updated_at' => fake()->boolean(60) ? fake()->dateTimeBetween('-30 days', 'now') : null,
            'last_synced_at' => fake()->boolean(60) ? fake()->dateTimeBetween('-15 days', 'now') : null,
        ];
    }
}
