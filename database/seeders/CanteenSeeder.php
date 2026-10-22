<?php

namespace Database\Seeders;

use App\Models\CanteenEntry;
use Illuminate\Database\Seeder;

class CanteenSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * A trailing-30-day window of daily canteen entries, in the same range
     * as the real spreadsheet this feature replaces (Purchases ~13,000-
     * 15,700, Profit ~4,100-5,100, Daily Cost one of a handful of recurring
     * figures) — so the Canteen page has realistic data to browse without
     * waiting for real usage. updateOrCreate keyed on the date, so rerunning
     * this seeder just refreshes the same 30 days rather than duplicating
     * them (entry_date is unique).
     */
    public function run(): void
    {
        $dailyCosts = [200, 250, 300, 760];

        for ($daysAgo = 29; $daysAgo >= 0; $daysAgo--) {
            $date = now()->subDays($daysAgo)->toDateString();

            // A gentle day-to-day wobble around realistic midpoints, not
            // pure randomness — keeps the numbers looking like a real
            // business's daily swings instead of noise.
            $purchases = 14200 + (($daysAgo * 37) % 1500);
            $profit = 4500 + (($daysAgo * 11) % 550);
            $dailyCost = $dailyCosts[$daysAgo % count($dailyCosts)];

            CanteenEntry::updateOrCreate(
                ['entry_date' => $date],
                [
                    'total_purchases' => $purchases,
                    'total_profit' => $profit,
                    'daily_cost' => $dailyCost,
                ]
            );
        }
    }
}
