<?php

namespace Database\Factories;

use App\Models\CanteenEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanteenEntry>
 */
class CanteenEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            // unique() on dateTimeBetween()->format('Y-m-d') would track
            // uniqueness of the pre-format DateTime (second precision), not
            // the resulting calendar day — distinct DateTimes collapse to
            // the same day easily in a narrow window, breaking entry_date's
            // uniqueness constraint. Applying unique() to the day offset
            // itself avoids that.
            'entry_date' => now()->subDays(fake()->unique()->numberBetween(0, 1000))->toDateString(),
            'total_purchases' => fake()->randomFloat(2, 10000, 16000),
            'total_profit' => fake()->randomFloat(2, 4000, 5200),
            'daily_cost' => fake()->randomElement([200, 250, 300, 760]),
        ];
    }
}
