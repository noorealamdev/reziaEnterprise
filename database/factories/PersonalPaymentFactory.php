<?php

namespace Database\Factories;

use App\Models\PersonalContact;
use App\Models\PersonalPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonalPayment>
 */
class PersonalPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'personal_contact_id' => PersonalContact::factory(),
            'payment_date' => now()->toDateString(),
            'amount' => fake()->randomFloat(2, 100, 5000),
            'remarks' => null,
        ];
    }
}
