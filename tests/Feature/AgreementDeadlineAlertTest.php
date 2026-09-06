<?php

use App\Mail\AgreementDeadlineAlertMail;
use App\Models\CompanyAgreement;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('an agreement expiring within the alert window triggers an alert and a distant one does not', function () {
    Mail::fake();

    $superAdmin = User::factory()->create();

    $soonToExpire = CompanyAgreement::factory()->create(['end_date' => now()->addDays(10)->toDateString()]);
    $farAway = CompanyAgreement::factory()->create(['end_date' => now()->addDays(90)->toDateString()]);

    $this->artisan('report:agreement-deadlines')->assertSuccessful();

    Mail::assertSent(AgreementDeadlineAlertMail::class, 1);
    Mail::assertSent(AgreementDeadlineAlertMail::class, function ($mail) use ($superAdmin, $soonToExpire, $farAway) {
        $ids = $mail->agreements->pluck('agreement')->pluck('id');

        return $mail->hasTo($superAdmin->email)
            && $ids->contains($soonToExpire->id)
            && ! $ids->contains($farAway->id);
    });
});

test('an already-expired agreement is not alerted on', function () {
    Mail::fake();

    User::factory()->create();
    CompanyAgreement::factory()->create(['end_date' => now()->subDays(2)->toDateString()]);

    $this->artisan('report:agreement-deadlines')->assertSuccessful();

    Mail::assertNothingSent();
});

test('an agreement already alerted recently is not alerted again', function () {
    Mail::fake();

    User::factory()->create();
    CompanyAgreement::factory()->create(['end_date' => now()->addDays(10)->toDateString()]);

    $this->artisan('report:agreement-deadlines')->assertSuccessful();
    Mail::assertSent(AgreementDeadlineAlertMail::class, 1);

    Mail::fake();
    $this->artisan('report:agreement-deadlines')->assertSuccessful();

    Mail::assertNothingSent();
});

test('an agreement is re-alerted once the resend window has passed', function () {
    Mail::fake();

    User::factory()->create();
    CompanyAgreement::factory()->create([
        'end_date' => now()->addDays(10)->toDateString(),
        'last_alerted_at' => now()->subDays(8),
    ]);

    $this->artisan('report:agreement-deadlines')->assertSuccessful();

    Mail::assertSent(AgreementDeadlineAlertMail::class, 1);
});

test('nothing is sent when there are no active super admins even if an agreement qualifies', function () {
    Mail::fake();

    User::factory()->inactive()->create();
    CompanyAgreement::factory()->create(['end_date' => now()->addDays(10)->toDateString()]);

    $this->artisan('report:agreement-deadlines')->assertSuccessful();

    Mail::assertNothingSent();
});
