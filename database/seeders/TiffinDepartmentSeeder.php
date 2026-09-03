<?php

namespace Database\Seeders;

use App\Models\TiffinDepartment;
use Illuminate\Database\Seeder;

class TiffinDepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['Swing', 'Wash Worker'] as $name) {
            TiffinDepartment::query()->updateOrCreate(['name' => $name]);
        }
    }
}
