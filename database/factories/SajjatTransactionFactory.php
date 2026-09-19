<?php

namespace Database\Factories;

use App\Models\SajjatTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SajjatTransaction>
 */
class SajjatTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_date' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'type' => SajjatTransaction::TYPE_EXPENSE,
            'wallet' => fake()->randomElement(array_keys(SajjatTransaction::WALLETS)),
            'amount' => fake()->randomFloat(2, 50, 2000),
            'description' => fake()->sentence(3),
        ];
    }

    public function topUp(): static
    {
        return $this->state(fn () => ['type' => SajjatTransaction::TYPE_TOP_UP]);
    }
}
