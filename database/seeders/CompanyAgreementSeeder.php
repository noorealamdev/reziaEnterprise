<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyAgreement;
use Illuminate\Database\Seeder;

class CompanyAgreementSeeder extends Seeder
{
    /**
     * Seeds one agreement per state (active, expiring soon, already
     * expired) across the two dev companies, so the deadline alert and
     * the Dashboard/Company Agreements pages all have something to show
     * without anyone having to add data by hand first.
     */
    public function run(): void
    {
        $ananta = Company::where('code', 'AAL')->first();
        $simba = Company::where('code', 'SIMBA')->first();

        if (! $ananta || ! $simba) {
            return;
        }

        CompanyAgreement::updateOrCreate(
            ['company_id' => $ananta->id, 'title' => 'Service Agreement 2026-2027'],
            [
                'start_date' => now()->subMonths(6)->toDateString(),
                'end_date' => now()->addMonths(6)->toDateString(),
                'remarks' => 'Renewed annually — covers Tiffin and Daily Basic Labour.',
            ]
        );

        CompanyAgreement::updateOrCreate(
            ['company_id' => $simba->id, 'title' => 'Supply & Service Agreement'],
            [
                'start_date' => now()->subMonths(11)->toDateString(),
                'end_date' => now()->addDays(15)->toDateString(),
                'remarks' => 'Up for renewal soon — confirm terms before it lapses.',
            ]
        );

        CompanyAgreement::updateOrCreate(
            ['company_id' => $simba->id, 'title' => 'Original Onboarding Agreement (2025)'],
            [
                'start_date' => now()->subYears(2)->toDateString(),
                'end_date' => now()->subMonths(1)->toDateString(),
                'remarks' => 'Superseded by the current Supply & Service Agreement.',
            ]
        );
    }
}
