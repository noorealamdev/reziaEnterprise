<?php

namespace App\Console\Commands;

use App\Mail\AgreementDeadlineAlertMail;
use App\Models\CompanyAgreement;
use App\Models\User;
use App\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendAgreementDeadlineAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:agreement-deadlines';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Email Super Admins about company agreements whose deadline is coming up';

    public function handle(): int
    {
        $alertDays = (int) config('reports.agreement_deadline_alert_days');
        $resendDays = (int) config('reports.agreement_deadline_resend_days');
        $alertCutoff = now()->addDays($alertDays)->toDateString();
        $resendCutoff = now()->subDays($resendDays);

        $agreements = CompanyAgreement::with('company')
            ->whereDate('end_date', '>=', now()->toDateString())
            ->whereDate('end_date', '<=', $alertCutoff)
            ->where(fn ($query) => $query->whereNull('last_alerted_at')->orWhere('last_alerted_at', '<=', $resendCutoff))
            ->get();

        if ($agreements->isEmpty()) {
            $this->info('No agreement deadlines qualify for an alert.');

            return self::SUCCESS;
        }

        $recipients = User::where('role', UserRole::SuperAdmin)->where('is_active', true)->get();

        if ($recipients->isEmpty()) {
            $this->info('No active Super Admins to notify — skipping agreement deadline alert.');

            return self::SUCCESS;
        }

        CompanyAgreement::whereIn('id', $agreements->pluck('id'))->update(['last_alerted_at' => now()]);

        $mailAgreements = $agreements->map(fn (CompanyAgreement $agreement) => [
            'agreement' => $agreement,
            'daysRemaining' => (int) round(now()->diffInDays($agreement->end_date, absolute: true)),
        ]);

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(new AgreementDeadlineAlertMail($mailAgreements));
        }

        $this->info("Agreement deadline alert sent to {$recipients->count()} Super Admin(s) for {$agreements->count()} agreement(s).");

        return self::SUCCESS;
    }
}
