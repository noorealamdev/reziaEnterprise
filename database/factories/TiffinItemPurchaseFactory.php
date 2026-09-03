<?php

namespace Database\Factories;

use App\Models\TiffinItem;
use App\Models\TiffinItemPurchase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TiffinItemPurchase>
 */
class TiffinItemPurchaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(2, 50, 500);
        $costRate = fake()->randomFloat(2, 5, 20);

        return [
            'tiffin_item_id' => fn () => TiffinItem::query()->inRandomOrder()->value('id')
                ?? TiffinItem::create(['name' => fake()->unique()->word()])->id,
            'purchase_date' => now()->toDateString(),
            'quantity' => $quantity,
            'cost_rate' => $costRate,
            'cost_amount' => round($quantity * $costRate, 2),
            'supplier_name' => fake()->company(),
            'remarks' => null,
            'created_by' => null,
        ];
    }
}
