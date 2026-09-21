<?php

namespace Database\Factories;

use App\Models\Newspaper;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Newspaper>
 */
class NewspaperFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Daily',
            'journalist_name' => fake()->name(),
            'phone' => '01'.fake()->numerify('#########'),
            'whatsapp' => null,
            'monthly_amount' => null,
            'is_active' => true,
        ];
    }
}
