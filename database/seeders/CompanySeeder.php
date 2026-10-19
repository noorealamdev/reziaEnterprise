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
        // original spreadsheet analysis, across its own two tiffin
        // departments (Swing, Wash Worker) — not Simba's own units below.
        $company->serviceCategories()->sync(ServiceCategory::pluck('id'));
        $company->tiffinDepartments()->sync(
            TiffinDepartment::whereIn('name', ['Swing', 'Wash Worker'])->pluck('id')
        );

        // Simba Fashion is a client like any other (receives Tiffin and
        // other services, gets invoiced the normal way) but also sells
        // Rezia goods on the side — tracked separately via Company
        // Purchases, deliberately not netted against what Simba owes on
        // its invoices (client confirmed 2026-09-05: keep the two ledgers
        // independent).
        $simba = Company::query()->updateOrCreate(
            ['code' => 'SFL'],
            [
                'name' => 'Simba Fashion Ltd',
                'is_active' => true,
            ]
        );

        // Simba operates 4 physical units and bills Daily Basic Labour and
        // Tiffin separately per unit — these 3 "departments" are really
        // Simba's own units, reusing the Tiffin department mechanism (see
        // the hardcoded company code 'SFL' checks on the Job Entry and
        // Generate Invoice forms).
        $simba->serviceCategories()->sync(ServiceCategory::pluck('id'));
        $simba->tiffinDepartments()->sync(
            TiffinDepartment::whereIn('name', ['Simba Unit-01', 'Simba Unit-02', 'Simba Unit-03', 'Simba Unit-04'])->pluck('id')
        );
    }
}
