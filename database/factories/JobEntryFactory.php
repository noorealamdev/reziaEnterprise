<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\JobEntry;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobEntry>
 */
class JobEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(2, 1, 50);
        $costRate = fake()->randomFloat(2, 5, 100);
        $billRate = $costRate + fake()->randomFloat(2, 1, 20);

        return [
            'company_id' => Company::factory(),
            'service_category_id' => fn () => ServiceCategory::query()->inRandomOrder()->value('id')
                ?? ServiceCategory::query()->create([
                    'name' => fake()->unique()->word(),
                    'invoice_code' => strtoupper(fake()->unique()->lexify('???')),
                    'sort_order' => 0,
                ])->id,
            'tiffin_department_id' => null,
            'in_charge' => null,
            'entry_date' => now()->toDateString(),
            'supply_type' => fake()->randomElement(['Egg', 'Bread', 'Local Sand Supply', 'Daily Basic Labour']),
            'buyer' => null,
            'style' => null,
            'floor' => null,
            'challan_no' => null,
            'company_adv_payment' => null,
            'quantity' => $quantity,
            'cost_rate' => $costRate,
            'bill_rate' => $billRate,
            'cost_amount' => round($quantity * $costRate, 2),
            'bill_amount' => round($quantity * $billRate, 2),
            'is_off_day' => false,
            'remarks' => null,
        ];
    }
}
