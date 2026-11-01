<?php

use App\Mail\DailyReportMail;
use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\User;
use App\Support\BusinessSnapshot;
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

test('the daily report leads with profit and loss in plain words, matching the Dashboard', function () {
    $this->travelTo('2026-11-05 20:00:00');
    User::factory()->create();
    JobEntry::factory()->create(['entry_date' => '2026-10-10', 'bill_amount' => 1000, 'cost_amount' => 1500]);
    JobEntry::factory()->create(['entry_date' => '2026-11-02', 'bill_amount' => 800, 'cost_amount' => 300]);

    Mail::fake();
    $this->artisan('report:daily')->assertSuccessful();

    Mail::assertSent(DailyReportMail::class, function ($mail) {
        $html = $mail->render();

        return $mail->data['profitAndLoss'] == BusinessSnapshot::profitAndLoss()
            && str_contains($html, 'October 2026: Loss 500.00')
            && str_contains($html, 'November so far: Profit 500.00')
            && str_contains($html, 'Since October 2026: 0.00')
            && strpos($html, 'October 2026: Loss 500.00') < strpos($html, 'Invoice Overview');
    });
});

test('the daily report lists late bills, flags them in the subject, and shows what is due soon', function () {
    $this->travelTo('2026-11-15 20:00:00');
    User::factory()->create();
    $company = Company::factory()->create(['name' => 'Late Factory Ltd']);
    JobEntry::factory()->create(['company_id' => $company->id, 'entry_date' => '2026-10-10', 'bill_amount' => 700]);

    Mail::fake();
    $this->artisan('report:daily')->assertSuccessful();

    Mail::assertSent(DailyReportMail::class, function ($mail) {
        $html = $mail->render();

        return $mail->envelope()->subject === 'Daily Report — Nov 15, 2026 — bills late'
            && str_contains($html, 'Bills not submitted to the factory')
            && str_contains($html, 'Late Factory Ltd')
            && str_contains($html, 'Not generated, 5 days late');
    });

    $this->travelTo('2026-11-05 20:00:00');
    Mail::fake();
    $this->artisan('report:daily')->assertSuccessful();

    Mail::assertSent(DailyReportMail::class, function ($mail) {
        $html = $mail->render();

        return $mail->envelope()->subject === 'Daily Report — Nov 5, 2026'
            && ! str_contains($html, 'Bills not submitted to the factory')
            && str_contains($html, 'October bills are due by 10 November.')
            && ! str_contains($html, 'overdue')
            && str_contains($html, 'since Oct 2026');
    });
});

test('the daily report counts past paper bills in the invoice overview, so paid and unpaid add up to all bills', function () {
    User::factory()->create();
    $company = Company::factory()->create();
    $category = makeServiceCategory('Daily Basic Labour');

    foreach ([['paid', 5000], ['due', 3000]] as [$status, $amount]) {
        Invoice::create([
            'company_id' => $company->id,
            'service_category_id' => $category->id,
            'invoice_number' => 'PAST-'.$status,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => $status,
            'manual_amount' => $amount,
        ]);
    }

    Mail::fake();
    $this->artisan('report:daily')->assertSuccessful();

    Mail::assertSent(DailyReportMail::class, function ($mail) {
        $html = $mail->render();
        $overview = $mail->data['invoiceOverview']->keyBy('statusValue');

        return $overview['paid']['amount'] === 5000.0
            && $overview['unpaid']['amount'] === 3000.0
            && $mail->data['billedTotal'] === 8000.0
            && str_contains($html, 'Factories still owe 3,000.00');
    });
});

test('with nothing recorded this month the companies table shows last month instead of zeros', function () {
    $this->travelTo('2026-11-01 20:00:00');
    User::factory()->create();
    $company = Company::factory()->create(['name' => 'Worked Last Month Ltd']);
    Company::factory()->create(['name' => 'Never Worked Ltd']);
    JobEntry::factory()->create(['company_id' => $company->id, 'entry_date' => '2026-10-10', 'bill_amount' => 700, 'cost_amount' => 200]);

    Mail::fake();
    $this->artisan('report:daily')->assertSuccessful();

    Mail::assertSent(DailyReportMail::class, function ($mail) {
        $html = $mail->render();

        return str_contains($html, 'Companies — October 2026')
            && str_contains($html, 'No work is recorded for November yet')
            && str_contains($html, 'Worked Last Month Ltd')
            && ! str_contains($html, 'Never Worked Ltd')
            && $mail->data['companiesRows']->sole()['profitAllWork'] === 500.0;
    });
});

test('company names with an ampersand stay readable in the email', function () {
    $this->travelTo('2026-10-15 20:00:00');
    User::factory()->create();
    $category = makeServiceCategory('Embroidery & Print');
    JobEntry::factory()->create(['service_category_id' => $category->id, 'entry_date' => '2026-09-10', 'bill_amount' => 700]);

    Mail::fake();
    $this->artisan('report:daily')->assertSuccessful();

    Mail::assertSent(DailyReportMail::class, fn ($mail) => str_contains($mail->render(), 'Embroidery &amp; Print')
        && ! str_contains($mail->render(), '&amp;amp;'));
});

test('agreement deadlines count whole calendar days at any time of day', function () {
    User::factory()->create();
    CompanyAgreement::factory()->create(['end_date' => '2026-11-02']);
    CompanyAgreement::factory()->create(['end_date' => '2026-08-22']);

    $this->travelTo('2026-11-01 20:00:00');

    $rows = BusinessSnapshot::agreementDeadlines();

    expect($rows->pluck('daysRemaining')->all())->toBe([71, 1]);
});
