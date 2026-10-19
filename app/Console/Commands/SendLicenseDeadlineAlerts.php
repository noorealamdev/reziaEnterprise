<?php

namespace App\Console\Commands;

use App\Mail\LicenseDeadlineAlertMail;
use App\Models\License;
use App\Models\User;
use App\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendLicenseDeadlineAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:license-deadlines';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Email Super Admins about company licenses whose renewal deadline is coming up';

    public function handle(): int
    {
        $alertDays = (int) config('reports.license_deadline_alert_days');
        $resendDays = (int) config('reports.license_deadline_resend_days');
        $alertCutoff = now()->addDays($alertDays)->toDateString();
        $resendCutoff = now()->subDays($resendDays);

        $licenses = License::whereDate('expiry_date', '>=', now()->toDateString())
            ->whereDate('expiry_date', '<=', $alertCutoff)
            ->where(fn ($query) => $query->whereNull('last_alerted_at')->orWhere('last_alerted_at', '<=', $resendCutoff))
            ->get();

        if ($licenses->isEmpty()) {
            $this->info('No license deadlines qualify for an alert.');

            return self::SUCCESS;
        }

        $recipients = User::where('role', UserRole::SuperAdmin)->where('is_active', true)->get();

        if ($recipients->isEmpty()) {
            $this->info('No active Super Admins to notify — skipping license deadline alert.');

            return self::SUCCESS;
        }

        License::whereIn('id', $licenses->pluck('id'))->update(['last_alerted_at' => now()]);

        $mailLicenses = $licenses->map(fn (License $license) => [
            'license' => $license,
            'daysRemaining' => (int) round(now()->diffInDays($license->expiry_date, absolute: true)),
        ]);

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(new LicenseDeadlineAlertMail($mailLicenses));
        }

        $this->info("License deadline alert sent to {$recipients->count()} Super Admin(s) for {$licenses->count()} license(s).");

        return self::SUCCESS;
    }
}
