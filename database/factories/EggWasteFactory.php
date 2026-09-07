<?php

namespace Database\Factories;

use App\Models\EggWaste;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EggWaste>
 */
class EggWasteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'waste_date' => now()->toDateString(),
            'quantity' => fake()->randomFloat(2, 5, 50),
            'remarks' => null,
            'created_by' => null,
        ];
    }
}
