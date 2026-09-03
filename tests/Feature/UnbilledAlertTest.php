<?php

use App\Mail\UnbilledAlertMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\UnbilledAlert;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('a group older than the aging threshold triggers an alert and a younger one does not', function () {
    Mail::fake();

    $superAdmin = User::factory()->create();

    $oldEntry = JobEntry::factory()->create(['entry_date' => now()->subDays(20)]);
    $recentEntry = JobEntry::factory()->create(['entry_date' => now()->subDays(3)]);

    $this->artisan('report:unbilled-alerts')->assertSuccessful();

    Mail::assertSent(UnbilledAlertMail::class, 1);
    Mail::assertSent(UnbilledAlertMail::class, function ($mail) use ($superAdmin, $oldEntry, $recentEntry) {
        $companyIds = $mail->groups->pluck('company')->pluck('id');

        return $mail->hasTo($superAdmin->email)
            && $companyIds->contains($oldEntry->company_id)
            && ! $companyIds->contains($recentEntry->company_id);
    });
});

test('a group already alerted recently is not alerted again', function () {
    Mail::fake();

    User::factory()->create();

    $entry = JobEntry::factory()->create(['entry_date' => now()->subDays(20)]);

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

test('a group is re-alerted once the resend window has passed', function () {
    Mail::fake();

    User::factory()->create();

    $entry = JobEntry::factory()->create(['entry_date' => now()->subDays(20)]);

    UnbilledAlert::create([
        'company_id' => $entry->company_id,
        'service_category_id' => $entry->service_category_id,
        'last_alerted_at' => now()->subDays(8),
    ]);

    $this->artisan('report:unbilled-alerts')->assertSuccessful();

    Mail::assertSent(UnbilledAlertMail::class, 1);
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
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'due',
    ]);

    $entry = JobEntry::factory()->create([
        'company_id' => $company->id,
        'service_category_id' => $category->id,
        'entry_date' => now()->subDays(20),
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

test('nothing is sent when there are no active super admins even if a group qualifies', function () {
    Mail::fake();

    User::factory()->inactive()->create();
    JobEntry::factory()->create(['entry_date' => now()->subDays(20)]);

    $this->artisan('report:unbilled-alerts')->assertSuccessful();

    Mail::assertNothingSent();
});
