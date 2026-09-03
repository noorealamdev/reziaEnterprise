<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ServiceCategory;
use App\Models\TiffinDepartment;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $company = Company::query()->updateOrCreate(
            ['code' => 'AAL'],
            [
                'name' => 'Ananta Apparels Ltd',
                'is_active' => true,
                'tiffin_bill_rate' => 30.00,
            ]
        );

        // Ananta Apparels Ltd uses every service line observed in the
        // original spreadsheet analysis, across both tiffin departments.
        $company->serviceCategories()->sync(ServiceCategory::pluck('id'));
        $company->tiffinDepartments()->sync(TiffinDepartment::pluck('id'));
    }
}
