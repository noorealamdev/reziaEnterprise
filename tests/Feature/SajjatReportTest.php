<?php

use App\Mail\SajjatReportMail;
use App\Models\SajjatTransaction;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;

test('the sajjat report is emailed to every active super admin only', function () {
    Mail::fake();

    $superAdmin = User::factory()->create();
    $inactiveSuperAdmin = User::factory()->inactive()->create();
    $accountant = User::factory()->accountant()->create();
    $staff = User::factory()->staff()->create();
    SajjatTransaction::factory()->topUp()->create(['wallet' => 'cash', 'amount' => 1000]);

    $this->artisan('report:sajjat')->assertSuccessful();

    Mail::assertSent(SajjatReportMail::class, 1);
    Mail::assertSent(SajjatReportMail::class, fn ($mail) => $mail->hasTo($superAdmin->email));
    Mail::assertNotSent(SajjatReportMail::class, fn ($mail) => $mail->hasTo($inactiveSuperAdmin->email));
    Mail::assertNotSent(SajjatReportMail::class, fn ($mail) => $mail->hasTo($accountant->email));
    Mail::assertNotSent(SajjatReportMail::class, fn ($mail) => $mail->hasTo($staff->email));
});

test('the sajjat report sends nothing before the ledger has been used, or with no active super admin', function () {
    Mail::fake();

    User::factory()->create();
    $this->artisan('report:sajjat')->assertSuccessful();
    Mail::assertNothingSent();

    User::query()->update(['is_active' => false]);
    SajjatTransaction::factory()->create();
    $this->artisan('report:sajjat')->assertSuccessful();
    Mail::assertNothingSent();
});

test('the sajjat report carries today\'s activity, the all-time balances and the month so far', function () {
    Mail::fake();
    // Pinned mid-month so "earlier this month" is never also "today".
    $this->travelTo(now()->startOfMonth()->addDays(14));

    User::factory()->create();
    // Earlier in a previous month: only affects the balances.
    SajjatTransaction::factory()->topUp()->create(['wallet' => 'bkash', 'amount' => 5000, 'transaction_date' => now()->subMonths(2)->toDateString()]);
    // Earlier this month but not today: month totals, not today's list.
    SajjatTransaction::factory()->create(['wallet' => 'cash', 'amount' => 100, 'transaction_date' => now()->startOfMonth()->toDateString(), 'description' => 'Earlier tea']);
    // Today.
    SajjatTransaction::factory()->topUp()->create(['wallet' => 'cash', 'amount' => 2000, 'transaction_date' => now()->toDateString()]);
    SajjatTransaction::factory()->create(['wallet' => 'cash', 'amount' => 350, 'transaction_date' => now()->toDateString(), 'description' => 'Transport today']);

    $this->artisan('report:sajjat')->assertSuccessful();

    Mail::assertSent(SajjatReportMail::class, function ($mail) {
        return $mail->data['balances'] === ['bkash' => 5000.0, 'cash' => 1550.0]
            && $mail->data['totalBalance'] === 6550.0
            && $mail->data['todayTopUps'] === 2000.0
            && $mail->data['todaySpent'] === 350.0
            && $mail->data['todayEntries']->count() === 2
            && $mail->data['monthTopUps'] === 2000.0
            && $mail->data['monthSpent'] === 450.0;
    });
});

test('the email renders the balances, overspent warning and today\'s entries', function () {
    User::factory()->create();
    SajjatTransaction::factory()->topUp()->create(['wallet' => 'cash', 'amount' => 100, 'transaction_date' => now()->toDateString()]);
    SajjatTransaction::factory()->create(['wallet' => 'cash', 'amount' => 250, 'transaction_date' => now()->toDateString(), 'description' => 'Emergency repair']);

    Mail::fake();
    $this->artisan('report:sajjat')->assertSuccessful();

    Mail::assertSent(SajjatReportMail::class, function ($mail) {
        $html = $mail->render();

        return str_contains($html, 'Balance available now')
            && str_contains($html, 'Overspent')
            && str_contains($html, 'Emergency repair')
            && str_contains($html, '−250.00');
    });
});

test('a quiet day says so instead of listing entries', function () {
    User::factory()->create();
    SajjatTransaction::factory()->topUp()->create(['transaction_date' => now()->subDays(3)->toDateString()]);

    Mail::fake();
    $this->artisan('report:sajjat')->assertSuccessful();

    Mail::assertSent(SajjatReportMail::class, fn ($mail) => str_contains($mail->render(), 'No top-ups or expenses were recorded today.'));
});

test('the sajjat report is scheduled daily at 8 PM Dhaka time', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'report:sajjat'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 20 * * *');
    expect($event->timezone)->toBe('Asia/Dhaka');
});
