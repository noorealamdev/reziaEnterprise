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
        foreach (['Swing', 'Wash Worker', 'Simba Unit-01', 'Simba Unit-02', 'Simba Unit-03', 'Simba Unit-04'] as $name) {
            TiffinDepartment::query()->updateOrCreate(['name' => $name]);
        }
    }
}
