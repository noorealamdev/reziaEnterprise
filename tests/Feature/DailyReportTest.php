<?php

use App\Mail\DailyReportMail;
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
