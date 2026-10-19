<?php

namespace Database\Factories;

use App\Models\EggInCharge;
use App\Models\EggInChargeTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EggInChargeTransaction>
 */
class EggInChargeTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'egg_in_charge_id' => EggInCharge::factory(),
            'type' => EggInChargeTransaction::TYPE_EXPENSE,
            'transaction_date' => now()->toDateString(),
            'amount' => fake()->randomFloat(2, 100, 2000),
            'description' => fake()->sentence(4),
            'remarks' => null,
            'created_by' => null,
        ];
    }
}
