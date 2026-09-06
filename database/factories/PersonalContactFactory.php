<?php

namespace Database\Factories;

use App\Models\PersonalContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonalContact>
 */
class PersonalContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'factory_name' => fake()->company(),
            'phone' => fake()->phoneNumber(),
            'remarks' => null,
        ];
    }
}
