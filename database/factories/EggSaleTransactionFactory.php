<?php

namespace Database\Factories;

use App\Models\EggSaleTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EggSaleTransaction>
 */
class EggSaleTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(2, 10, 300);
        $rate = fake()->randomFloat(2, 10, 20);

        return [
            'transaction_date' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'type' => EggSaleTransaction::TYPE_SOLD,
            'quantity' => $quantity,
            'rate' => $rate,
            'amount' => round($quantity * $rate, 2),
            'description' => fake()->company(),
        ];
    }

    public function received(): static
    {
        return $this->state(fn () => ['type' => EggSaleTransaction::TYPE_RECEIVED, 'rate' => null, 'amount' => null]);
    }

    public function payment(): static
    {
        return $this->state(fn () => [
            'type' => EggSaleTransaction::TYPE_PAYMENT,
            'quantity' => 0,
            'rate' => null,
            'amount' => fake()->randomFloat(2, 500, 10000),
            'description' => 'Weekly settlement',
        ]);
    }
}
