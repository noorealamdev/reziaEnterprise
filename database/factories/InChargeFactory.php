<?php

namespace Database\Factories;

use App\Models\InCharge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InCharge>
 */
class InChargeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->numerify('01#########'),
            'is_active' => true,
        ];
    }
}
