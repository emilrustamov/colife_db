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

        $contractTypes = [null, 'Airbnb', 'Booking', 'Short term (less than a month)', 'Long term (1 month)', 'Long term (2+ months)', 'Ejari'];
        $dealTypes = [null, 'New Contract', 'Extension', 'Relocation', 'Relocation with extension', 'Financial Amendment'];
        $paymentTypes = [null, 'Bank transfer', 'Cash', 'Cryptocurrency', 'Terminal', 'Stripe', 'Alma app'];

        return [
            'id' => Str::uuid()->toString(),
            'bitrix_id' => null,
            'title' => fake()->boolean(70) ? 'Contract_'.fake()->lastName().'_Un.'.fake()->numberBetween(1, 20) : null,
            'stage_id' => null,
            'unit_id' => null,
            'tenant_contact_id' => null,
            'co_tenant_contact_id' => null,
            'deal_id' => fake()->boolean(40) ? fake()->numberBetween(10000, 9999999) : null,
            'contract_type' => fake()->randomElement($contractTypes),
            'type_of_deal' => fake()->randomElement($dealTypes),
            'type_of_payment' => fake()->randomElement($paymentTypes),
            'contract_start_date' => $checkIn->format('Y-m-d'),
            'contract_end_date' => $checkOut->format('Y-m-d'),
            'months_of_stay' => fake()->boolean(50) ? fake()->numberBetween(0, 12) : null,
            'rental_price' => fake()->boolean(40) ? fake()->randomFloat(2, 500, 15000) : null,
            'deposit' => fake()->boolean(40) ? fake()->randomFloat(2, 100, 5000) : null,
            'total_contract_amount' => fake()->boolean(40) ? fake()->randomFloat(2, 500, 50000) : null,
            'opportunity' => fake()->boolean(40) ? fake()->randomFloat(2, 100, 20000) : null,
            'currency_id' => fake()->randomElement([null, 'AED']),
            'passport_number' => fake()->boolean(40) ? strtoupper(fake()->bothify('??######')) : null,
            'check_in_date' => $checkIn->format('Y-m-d'),
            'check_out_date' => $checkOut->format('Y-m-d'),
            'is_deleted' => fake()->boolean(5),
            'bitrix_created_at' => fake()->boolean(60) ? fake()->dateTimeBetween('-60 days', '-1 day') : null,
            'bitrix_updated_at' => fake()->boolean(60) ? fake()->dateTimeBetween('-30 days', 'now') : null,
            'last_synced_at' => fake()->boolean(60) ? fake()->dateTimeBetween('-15 days', 'now') : null,
        ];
    }
}

