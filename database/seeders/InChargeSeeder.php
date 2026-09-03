<?php

namespace Database\Seeders;

use App\Models\InCharge;
use Illuminate\Database\Seeder;

class InChargeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['Mr. Monir', 'Sagor Vai'] as $name) {
            InCharge::query()->updateOrCreate(
                ['name' => $name],
                ['is_active' => true]
            );
        }
    }
}
