<?php

namespace Database\Factories;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'expense_date' => now()->toDateString(),
            'amount' => fake()->randomFloat(2, 50, 5000),
            'description' => fake()->randomElement(['Transport cost', 'Tea bill', 'Office supplies', 'Cash advance']),
            'remarks' => null,
        ];
    }
}
