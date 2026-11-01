<?php

namespace Database\Factories;

use App\Models\EggBuyer;
use App\Models\EggCashHandover;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EggCashHandover>
 */
class EggCashHandoverFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'egg_buyer_id' => EggBuyer::factory()->cashWallet(),
            'handed_on' => now()->toDateString(),
            'amount' => 100,
            'note' => null,
            'created_by' => null,
        ];
    }
}
