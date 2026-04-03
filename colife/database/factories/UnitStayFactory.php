<?php

namespace Database\Factories;

use App\Models\UnitStay;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UnitStayFactory extends Factory
{
    protected $model = UnitStay::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $checkIn = fake()->dateTimeBetween('-60 days', 'now');
        $checkOut = fake()->dateTimeBetween($checkIn->format('Y-m-d'), now()->addDays(45)->format('Y-m-d'));
        if ($checkOut < $checkIn) {
            $checkOut = (clone $checkIn)->addDays(fake()->numberBetween(1, 45));
        }

        $contractTypes = [null, 'monthly', 'weekly', 'short_term'];

        return [
            'id' => Str::uuid()->toString(),
            'bitrix_id' => null,
            'unit_id' => null,
            'tenant_contact_id' => null,
            'co_tenant_contact_id' => null,
            'deal_id' => fake()->boolean(40) ? fake()->numberBetween(10000, 9999999) : null,
            'contract_type' => fake()->randomElement($contractTypes),
            'check_in_date' => $checkIn->format('Y-m-d'),
            'check_out_date' => $checkOut->format('Y-m-d'),
            'is_deleted' => fake()->boolean(5),
            'bitrix_created_at' => fake()->boolean(60) ? fake()->dateTimeBetween('-60 days', '-1 day') : null,
            'bitrix_updated_at' => fake()->boolean(60) ? fake()->dateTimeBetween('-30 days', 'now') : null,
            'last_synced_at' => fake()->boolean(60) ? fake()->dateTimeBetween('-15 days', 'now') : null,
        ];
    }
}

