<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyPurchase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyPurchase>
 */
class CompanyPurchaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(2, 10, 200);
        $rate = fake()->randomFloat(2, 100, 1000);

        return [
            'company_id' => Company::factory(),
            'purchase_date' => now()->toDateString(),
            'description' => fake()->words(3, true),
            'quantity' => $quantity,
            'rate' => $rate,
            'amount' => round($quantity * $rate, 2),
            'remarks' => null,
            'created_by' => null,
        ];
    }
}
