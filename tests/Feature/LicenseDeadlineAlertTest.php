<?php

use App\Mail\LicenseDeadlineAlertMail;
use App\Models\License;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

test('a license expiring within the alert window triggers an alert and a distant one does not', function () {
    Mail::fake();

    $superAdmin = User::factory()->create();

    $soonToExpire = License::factory()->create(['expiry_date' => now()->addDays(10)->toDateString()]);
    $farAway = License::factory()->create(['expiry_date' => now()->addDays(90)->toDateString()]);

    $this->artisan('report:license-deadlines')->assertSuccessful();

    Mail::assertSent(LicenseDeadlineAlertMail::class, 1);
    Mail::assertSent(LicenseDeadlineAlertMail::class, function ($mail) use ($superAdmin, $soonToExpire, $farAway) {
        $ids = $mail->licenses->pluck('license')->pluck('id');

        return $mail->hasTo($superAdmin->email)
            && $ids->contains($soonToExpire->id)
            && ! $ids->contains($farAway->id);
    });
});

test('an already-expired license is not alerted on', function () {
    Mail::fake();

    User::factory()->create();
    License::factory()->create(['expiry_date' => now()->subDays(2)->toDateString()]);

    $this->artisan('report:license-deadlines')->assertSuccessful();

    Mail::assertNothingSent();
});

test('a license already alerted recently is not alerted again', function () {
    Mail::fake();

    User::factory()->create();
    License::factory()->create(['expiry_date' => now()->addDays(10)->toDateString()]);

    $this->artisan('report:license-deadlines')->assertSuccessful();
    Mail::assertSent(LicenseDeadlineAlertMail::class, 1);

    Mail::fake();
    $this->artisan('report:license-deadlines')->assertSuccessful();

    Mail::assertNothingSent();
});

test('a license is re-alerted once the resend window has passed', function () {
    Mail::fake();

    User::factory()->create();
    License::factory()->create([
        'expiry_date' => now()->addDays(10)->toDateString(),
        'last_alerted_at' => now()->subDays(8),
    ]);

    $this->artisan('report:license-deadlines')->assertSuccessful();

    Mail::assertSent(LicenseDeadlineAlertMail::class, 1);
});

test('nothing is sent when there are no active super admins even if a license qualifies', function () {
    Mail::fake();

    User::factory()->inactive()->create();
    License::factory()->create(['expiry_date' => now()->addDays(10)->toDateString()]);

    $this->artisan('report:license-deadlines')->assertSuccessful();

    Mail::assertNothingSent();
});
