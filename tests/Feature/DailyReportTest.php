<?php

use App\Mail\DailyReportMail;
use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('the daily report is emailed to every active super admin only', function () {
    Mail::fake();

    $superAdmin = User::factory()->create();
    $inactiveSuperAdmin = User::factory()->inactive()->create();
    $accountant = User::factory()->accountant()->create();
    $staff = User::factory()->staff()->create();

    $this->artisan('report:daily')->assertSuccessful();

    Mail::assertSent(DailyReportMail::class, 1);
    Mail::assertSent(DailyReportMail::class, fn ($mail) => $mail->hasTo($superAdmin->email));
    Mail::assertNotSent(DailyReportMail::class, fn ($mail) => $mail->hasTo($inactiveSuperAdmin->email));
    Mail::assertNotSent(DailyReportMail::class, fn ($mail) => $mail->hasTo($accountant->email));
    Mail::assertNotSent(DailyReportMail::class, fn ($mail) => $mail->hasTo($staff->email));
});

test('the daily report sends nothing when there are no active super admins', function () {
    Mail::fake();

    User::factory()->inactive()->create();

    $this->artisan('report:daily')->assertSuccessful();

    Mail::assertNothingSent();
});

test('the daily report includes the current unbilled total', function () {
    Mail::fake();

    $superAdmin = User::factory()->create();
    JobEntry::factory()->create(['bill_amount' => 500]);
    JobEntry::factory()->create(['bill_amount' => 250]);

    $this->artisan('report:daily')->assertSuccessful();

    Mail::assertSent(DailyReportMail::class, function ($mail) use ($superAdmin) {
        return $mail->hasTo($superAdmin->email)
            && $mail->data['unbilledTotal'] === 750.0
            && $mail->data['unbilledCount'] === 2;
    });
});

test('the daily report includes billed, outstanding, profit this month and the companies cost & profit table, matching the Dashboard\'s own figures', function () {
    Mail::fake();
    User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    $paidInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'DAILY-PAID',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'paid',
        'paid_at' => now()->toDateString(),
    ]);
    $dueInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'DAILY-DUE',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'due',
    ]);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 5000,
        'cost_amount' => 3000,
        'invoice_id' => $paidInvoice->id,
    ]);
    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 2000,
        'cost_amount' => 500,
        'invoice_id' => $dueInvoice->id,
    ]);

    $this->artisan('report:daily')->assertSuccessful();

    Mail::assertSent(DailyReportMail::class, function ($mail) {
        $companyRow = $mail->data['companiesRows']->first();

        return $mail->data['billedTotal'] === 7000.0
            && $mail->data['outstandingTotal'] === 2000.0
            && $mail->data['profitThisMonth'] === 2000.0
            && $companyRow['billed'] === 7000.0
            && $companyRow['cost'] === 3500.0
            && $companyRow['profitAllWork'] === 3500.0
            && $companyRow['profitPaid'] === 2000.0;
    });
});

test('the daily report email renders the companies table, invoice overview, agreement deadlines and today\'s activity', function () {
    User::factory()->create();
    $company = Company::factory()->create(['name' => 'Ananta Apparels Ltd']);
    $category = makeServiceCategory('Daily Basic Labour');

    $paidInvoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'RENDER-PAID',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'paid',
        'paid_at' => now()->toDateString(),
    ]);

    JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->toDateString(),
        'bill_amount' => 3000,
        'cost_amount' => 1000,
        'invoice_id' => $paidInvoice->id,
    ]);

    CompanyAgreement::factory()->create([
        'company_id' => $company->id,
        'title' => 'Annual Supply Agreement',
        'end_date' => now()->addDays(5)->toDateString(),
    ]);

    Mail::fake();
    $this->artisan('report:daily')->assertSuccessful();

    Mail::assertSent(DailyReportMail::class, function ($mail) {
        $html = $mail->render();

        return str_contains($html, 'Invoice Overview')
            && str_contains($html, 'Companies —')
            && str_contains($html, 'Ananta Apparels Ltd')
            && str_contains($html, 'Agreement Deadlines')
            && str_contains($html, 'Annual Supply Agreement')
            && str_contains($html, "Today's Activity")
            && str_contains($html, 'Daily Basic Labour');
    });
});

test('the daily report email says so instead of an empty table when nothing billed this month', function () {
    User::factory()->create();

    Mail::fake();
    $this->artisan('report:daily')->assertSuccessful();

    Mail::assertSent(DailyReportMail::class, fn ($mail) => str_contains($mail->render(), 'No billing activity this month yet.'));
});
