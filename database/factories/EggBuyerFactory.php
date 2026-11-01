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
            'address' => null,
            'remarks' => null,
            'is_active' => true,
            'created_by' => null,
        ];
    }

    public function cashWallet(): static
    {
        return $this->state(fn (array $attributes) => ['uses_cash_wallet' => true]);
    }
}
