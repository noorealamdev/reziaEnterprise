<?php

namespace Database\Factories;

use App\Models\EggInCharge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EggInCharge>
 */
class EggInChargeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->name(),
            'phone' => null,
            'remarks' => null,
            'created_by' => null,
        ];
    }
}
