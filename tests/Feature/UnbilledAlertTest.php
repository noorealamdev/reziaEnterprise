<?php

use App\Mail\UnbilledAlertMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\UnbilledAlert;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo('2026-10-15 09:00:00');
});

test('a bill past the 10th of the next month triggers an alert and work still within its deadline does not', function () {
    Mail::fake();

    $superAdmin = User::factory()->create();

    $lateEntry = JobEntry::factory()->create(['entry_date' => '2026-09-20', 'bill_amount' => 700]);
    $recentEntry = JobEntry::factory()->create(['entry_date' => '2026-10-02', 'bill_amount' => 300]);

    $this->artisan('report:unbilled-alerts')->assertSuccessful();

    Mail::assertSent(UnbilledAlertMail::class, 1);
    Mail::assertSent(UnbilledAlertMail::class, function ($mail) use ($superAdmin, $lateEntry, $recentEntry) {
        $companyIds = $mail->bills->pluck('company')->pluck('id');

        return $mail->hasTo($superAdmin->email)
            && $companyIds->contains($lateEntry->company_id)
            && ! $companyIds->contains($recentEntry->company_id);
    });
});

test('nothing is sent on or before the 10th', function () {
    Mail::fake();

    User::factory()->create();
    JobEntry::factory()->create(['entry_date' => '2026-09-20', 'bill_amount' => 700]);

    $this->travelTo('2026-10-10 09:00:00');
    $this->artisan('report:unbilled-alerts')->assertSuccessful();

    Mail::assertNothingSent();
});

test('the email names the factory, service, month, problem, days late and total', function () {
    User::factory()->create();
    $company = Company::factory()->create(['name' => 'Late Factory Ltd']);
    JobEntry::factory()->create(['company_id' => $company->id, 'entry_date' => '2026-09-20', 'bill_amount' => 700]);

    Mail::fake();
    $this->artisan('report:unbilled-alerts')->assertSuccessful();

    Mail::assertSent(UnbilledAlertMail::class, function ($mail) {
        $html = $mail->render();

        return $mail->envelope()->subject === 'Late Bills — 700.00 not submitted to the factory'
            && str_contains($html, 'Late Factory Ltd')
            && str_contains($html, 'Sep 2026')
            && str_contains($html, 'Not generated, 5 days late')
            && str_contains($html, '700.00');
    });
});

test('a generated bill with no signed copy is alerted until the copy is uploaded', function () {
    Mail::fake();

    User::factory()->create();
    $entry = JobEntry::factory()->create(['entry_date' => '2026-09-20', 'bill_amount' => 700]);
    $invoice = Invoice::create([
        'company_id' => $entry->company_id,
        'service_category_id' => $entry->service_category_id,
        'invoice_number' => 'TEST-LATE-UNSIGNED',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'status' => 'due',
    ]);
    $entry->update(['invoice_id' => $invoice->id]);

    $this->artisan('report:unbilled-alerts')->assertSuccessful();
    Mail::assertSent(UnbilledAlertMail::class, fn ($mail) => str_contains($mail->render(), 'Generated, no signed copy'));

    $invoice->update(['signed_copy_path' => 'signed-bills/copy.pdf']);
    UnbilledAlert::query()->update(['last_alerted_at' => now()->subDays(30)]);

    Mail::fake();
    $this->artisan('report:unbilled-alerts')->assertSuccessful();
    Mail::assertNothingSent();
});

test('zero-amount work never triggers an alert', function () {
    Mail::fake();

    User::factory()->create();
    JobEntry::factory()->create(['entry_date' => '2026-09-20', 'bill_amount' => 0]);

    $this->artisan('report:unbilled-alerts')->assertSuccessful();

    Mail::assertNothingSent();
});

test('a bill already alerted recently is not alerted again', function () {
    Mail::fake();

    User::factory()->create();

    $entry = JobEntry::factory()->create(['entry_date' => '2026-09-20', 'bill_amount' => 700]);

    $this->artisan('report:unbilled-alerts')->assertSuccessful();
    Mail::assertSent(UnbilledAlertMail::class, 1);

    Mail::fake();
    $this->artisan('report:unbilled-alerts')->assertSuccessful();

    Mail::assertNothingSent();

    $alert = UnbilledAlert::where('company_id', $entry->company_id)
        ->where('service_category_id', $entry->service_category_id)
        ->first();
    expect($alert)->not->toBeNull();
});

test('a bill is re-alerted once the resend window has passed', function () {
    Mail::fake();

    User::factory()->create();

    $entry = JobEntry::factory()->create(['entry_date' => '2026-09-20', 'bill_amount' => 700]);

    UnbilledAlert::create([
        'company_id' => $entry->company_id,
        'service_category_id' => $entry->service_category_id,
        'last_alerted_at' => now()->subDays(8),
    ]);

    $this->artisan('report:unbilled-alerts')->assertSuccessful();

    Mail::assertSent(UnbilledAlertMail::class, 1);
});

test('a new month going late is alerted the next morning even right after the last alert', function () {
    Mail::fake();

    User::factory()->create();

    $this->travelTo('2026-11-08 09:00:00');
    $september = JobEntry::factory()->create(['entry_date' => '2026-09-20', 'bill_amount' => 700]);
    JobEntry::factory()->create([
        'company_id' => $september->company_id,
        'service_category_id' => $september->service_category_id,
        'entry_date' => '2026-10-20',
        'bill_amount' => 300,
    ]);

    $this->artisan('report:unbilled-alerts')->assertSuccessful();
    Mail::assertSent(UnbilledAlertMail::class, fn ($mail) => $mail->bills->sum('amount') === 700.0);

    Mail::fake();
    $this->travelTo('2026-11-10 09:00:00');
    $this->artisan('report:unbilled-alerts')->assertSuccessful();
    Mail::assertNothingSent();

    Mail::fake();
    $this->travelTo('2026-11-11 09:00:00');
    $this->artisan('report:unbilled-alerts')->assertSuccessful();
    Mail::assertSent(UnbilledAlertMail::class, fn ($mail) => $mail->bills->sum('amount') === 1000.0);
});

test('a fully billed group is not alerted even with a stale alert row', function () {
    Mail::fake();

    User::factory()->create();

    $company = Company::factory()->create();
    $category = makeServiceCategory('Unbilled Alert Test Category');

    $invoice = Invoice::create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'invoice_number' => 'TEST-UNBILLED-ALERT-1',
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'status' => 'due',
        'signed_copy_path' => 'signed-bills/copy.pdf',
    ]);

    $entry = JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => '2026-09-20',
        'invoice_id' => $invoice->id,
    ]);

    UnbilledAlert::create([
        'company_id' => $entry->company_id,
        'service_category_id' => $entry->service_category_id,
        'last_alerted_at' => now()->subDays(30),
    ]);

    $this->artisan('report:unbilled-alerts')->assertSuccessful();

    Mail::assertNothingSent();
});

test('nothing is sent when there are no active super admins even if a bill is late', function () {
    Mail::fake();

    User::factory()->inactive()->create();
    JobEntry::factory()->create(['entry_date' => '2026-09-20', 'bill_amount' => 700]);

    $this->artisan('report:unbilled-alerts')->assertSuccessful();

    Mail::assertNothingSent();
    expect(UnbilledAlert::count())->toBe(0);
});
