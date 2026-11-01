<?php

namespace Database\Factories;

use App\Models\EggBuyer;
use App\Models\EggInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EggInvoice>
 */
class EggInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'egg_buyer_id' => EggBuyer::factory(),
            'invoice_number' => 'RE/EGG/'.fake()->unique()->numerify('T#####'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'status' => 'due',
        ];
    }
}
