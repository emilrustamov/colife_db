<?php

namespace Database\Factories;

use App\Models\BitrixUnitsSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

class BitrixUnitsSnapshotFactory extends Factory
{
    protected $model = BitrixUnitsSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $checkInDate = fake()->boolean(60) ? fake()->dateTimeBetween('-60 days', 'now') : null;

        return [
            'unit_id' => null,
            'apart_id' => null,
            'is_booked' => fake()->boolean(70),
            'is_moved_from_termination' => fake()->boolean(20),
            'is_stage_status' => fake()->boolean(50),
            'stage' => fake()->randomElement(['DT167_12:PREPARATION', 'DT167_12:RENT', 'DT167_12:TERMINATED']),
            'is_sharing' => fake()->boolean(15),
            'check_in_date' => $checkInDate,
            'is_idle' => fake()->boolean(25),
            'synced_at' => now(),
        ];
    }
}
