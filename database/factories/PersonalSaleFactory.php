<?php

namespace Database\Factories;

use App\Models\PersonalContact;
use App\Models\PersonalSale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonalSale>
 */
class PersonalSaleFactory extends Factory
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
            'sale_date' => now()->toDateString(),
            'description' => fake()->words(3, true),
            'amount' => fake()->randomFloat(2, 500, 20000),
            'remarks' => null,
        ];
    }
}
