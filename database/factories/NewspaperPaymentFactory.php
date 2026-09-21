<?php

namespace Database\Factories;

use App\Models\Newspaper;
use App\Models\NewspaperPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewspaperPayment>
 */
class NewspaperPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'newspaper_id' => Newspaper::factory(),
            'for_month' => now()->startOfMonth()->toDateString(),
            'amount' => fake()->randomFloat(2, 500, 5000),
            'paid_on' => now()->toDateString(),
            'wallet' => 'bkash',
        ];
    }
}
