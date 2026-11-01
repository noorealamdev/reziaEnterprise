<?php

namespace App\Console\Commands;

use App\Mail\UnbilledAlertMail;
use App\Models\UnbilledAlert;
use App\Models\User;
use App\Support\BusinessSnapshot;
use App\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendUnbilledAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:unbilled-alerts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Email Super Admins about bills not submitted to the factory by the deadline';

    public function handle(): int
    {
        $resendCutoff = now()->subDays((int) config('reports.unbilled_alert_resend_days'));
        $bills = BusinessSnapshot::billsNotSubmitted();

        // An alert sent before a bill became late can't have mentioned it, so
        // a month's bills are reported as soon as they pass the deadline.
        $isDue = $bills->contains(function (array $bill) use ($resendCutoff) {
            $alert = UnbilledAlert::where('company_id', $bill['company']->id)
                ->where('service_category_id', $bill['category']->id)
                ->first();

            return $alert === null
                || $alert->last_alerted_at <= $resendCutoff
                || $alert->last_alerted_at <= $bill['deadline']->copy()->endOfDay();
        });

        if (! $isDue) {
            $this->info('No late bills qualify for an alert.');

            return self::SUCCESS;
        }

        $recipients = User::where('role', UserRole::SuperAdmin)->where('is_active', true)->get();

        if ($recipients->isEmpty()) {
            $this->info('No active Super Admins to notify — skipping unbilled alert.');

            return self::SUCCESS;
        }

        foreach ($bills as $bill) {
            UnbilledAlert::updateOrCreate(
                ['company_id' => $bill['company']->id, 'service_category_id' => $bill['category']->id],
                ['last_alerted_at' => now()],
            );
        }

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(new UnbilledAlertMail($bills));
        }

        $this->info("Late bills alert sent to {$recipients->count()} Super Admin(s) for {$bills->count()} bill(s).");

        return self::SUCCESS;
    }
}
