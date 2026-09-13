<?php

namespace Database\Factories;

use App\Models\EggBuyer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EggBuyer>
 */
class EggBuyerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'phone' => null,
            'remarks' => null,
            'created_by' => null,
        ];
    }
}
