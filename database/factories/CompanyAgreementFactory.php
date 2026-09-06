<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyAgreement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyAgreement>
 */
class CompanyAgreementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'title' => fake()->words(3, true).' Agreement',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'remarks' => null,
            'created_by' => null,
        ];
    }
}
