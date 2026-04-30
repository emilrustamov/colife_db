<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $events = ['created', 'updated', 'status_changed', 'synced', 'deleted'];

        $oldValues = fake()->boolean(50) ? ['before' => fake()->sentence(3)] : null;
        $newValues = fake()->boolean(50) ? ['after' => fake()->sentence(3)] : null;

        return [
            'id' => Str::uuid()->toString(),
            'event' => fake()->randomElement($events),
            'subject_type' => null,
            'subject_id' => null,
            'user_id' => null,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'happened_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}

