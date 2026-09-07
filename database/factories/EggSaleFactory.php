<?php

namespace Database\Factories;

use App\Models\EggSale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EggSale>
 */
class EggSaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(2, 20, 300);
        $saleRate = fake()->randomFloat(2, 12, 18);

        return [
            'sale_date' => now()->toDateString(),
            'quantity' => $quantity,
            'sale_rate' => $saleRate,
            'sale_amount' => round($quantity * $saleRate, 2),
            'buyer_name' => fake()->company(),
            'payment_status' => 'cash',
            'in_charge' => null,
            'remarks' => null,
            'created_by' => null,
        ];
    }
}
