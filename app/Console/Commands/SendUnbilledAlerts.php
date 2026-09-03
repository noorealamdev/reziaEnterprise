<?php

namespace App\Console\Commands;

use App\Mail\UnbilledAlertMail;
use App\Models\JobEntry;
use App\Models\UnbilledAlert;
use App\Models\User;
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
    protected $description = 'Email Super Admins about company/category groups whose unbilled work is aging past the threshold';

    public function handle(): int
    {
        $agingDays = (int) config('reports.unbilled_alert_aging_days');
        $resendDays = (int) config('reports.unbilled_alert_resend_days');
        $agingCutoff = now()->subDays($agingDays)->startOfDay();

        $groups = JobEntry::whereNull('invoice_id')
            ->with(['company', 'serviceCategory'])
            ->get()
            ->groupBy(fn (JobEntry $entry) => $entry->company_id.'-'.$entry->service_category_id)
            ->map(fn ($entries) => [
                'company' => $entries->first()->company,
                'category' => $entries->first()->serviceCategory,
                'count' => $entries->count(),
                'total' => (float) $entries->sum('bill_amount'),
                'oldestEntryDate' => $entries->min('entry_date'),
            ])
            ->filter(fn ($group) => $group['oldestEntryDate'] <= $agingCutoff)
            ->values();

        $resendCutoff = now()->subDays($resendDays);

        $toAlert = $groups->filter(function ($group) use ($resendCutoff) {
            $alert = UnbilledAlert::where('company_id', $group['company']->id)
                ->where('service_category_id', $group['category']->id)
                ->first();

            return $alert === null || $alert->last_alerted_at <= $resendCutoff;
        })->values();

        if ($toAlert->isEmpty()) {
            $this->info('No unbilled groups qualify for an alert.');

            return self::SUCCESS;
        }

        $recipients = User::where('role', UserRole::SuperAdmin)->where('is_active', true)->get();

        if ($recipients->isEmpty()) {
            $this->info('No active Super Admins to notify — skipping unbilled alert.');

            return self::SUCCESS;
        }

        foreach ($toAlert as $group) {
            UnbilledAlert::updateOrCreate(
                ['company_id' => $group['company']->id, 'service_category_id' => $group['category']->id],
                ['last_alerted_at' => now()],
            );
        }

        $mailGroups = $toAlert->map(fn ($group) => [
            ...$group,
            'daysOutstanding' => (int) round(now()->diffInDays($group['oldestEntryDate'], absolute: true)),
        ]);

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(new UnbilledAlertMail($mailGroups));
        }

        $this->info("Unbilled alert sent to {$recipients->count()} Super Admin(s) for {$toAlert->count()} group(s).");

        return self::SUCCESS;
    }
}
