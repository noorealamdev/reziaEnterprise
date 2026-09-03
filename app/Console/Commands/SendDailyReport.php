<?php

namespace App\Console\Commands;

use App\Mail\DailyReportMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\User;
use App\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendDailyReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:daily';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Email the daily business report to every active Super Admin';

    public function handle(): int
    {
        $recipients = User::where('role', UserRole::SuperAdmin)->where('is_active', true)->get();

        if ($recipients->isEmpty()) {
            $this->info('No active Super Admins to notify — skipping daily report.');

            return self::SUCCESS;
        }

        $date = now();
        $data = $this->reportData($date);

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(new DailyReportMail($date, $data));
        }

        $this->info("Daily report sent to {$recipients->count()} Super Admin(s).");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function reportData(Carbon $date): array
    {
        $unbilled = JobEntry::whereNull('invoice_id')->with(['company', 'serviceCategory'])->get();

        // Same company+category grouping as the Dashboard's "Ready to
        // Invoice" panel (resources/views/livewire/dashboard/dashboard.blade.php).
        $readyToInvoice = $unbilled
            ->groupBy(fn (JobEntry $entry) => $entry->company_id.'-'.$entry->service_category_id)
            ->map(fn ($entries) => [
                'company' => $entries->first()->company,
                'category' => $entries->first()->serviceCategory,
                'count' => $entries->count(),
                'total' => $entries->sum('bill_amount'),
            ])
            ->filter(fn ($group) => $group['total'] > 0)
            ->sortByDesc('total')
            ->values();

        $outstandingTotal = Invoice::whereIn('status', ['due', 'partial'])
            ->withSum('jobEntries as amount', 'bill_amount')
            ->withSum('jobEntries as advancePaid', 'company_adv_payment')
            ->withSum('payments as paidViaPayments', 'amount')
            ->get()
            ->sum(function (Invoice $invoice) {
                $amount = (float) $invoice->amount;
                $vatAmount = $invoice->vat_percent ? round($amount * (float) $invoice->vat_percent / 100, 2) : 0;

                return max(0, $amount + $vatAmount - (float) $invoice->advancePaid - (float) $invoice->paidViaPayments);
            });

        $todayEntries = JobEntry::whereDate('entry_date', $date->toDateString())->get();

        return [
            'companyCount' => Company::count(),
            'todayEntryCount' => $todayEntries->count(),
            'todayTotal' => (float) $todayEntries->sum('bill_amount'),
            'unbilledTotal' => (float) $unbilled->sum('bill_amount'),
            'unbilledCount' => $unbilled->count(),
            'outstandingTotal' => (float) $outstandingTotal,
            'monthToDateTotal' => (float) JobEntry::whereBetween('entry_date', [
                $date->copy()->startOfMonth()->toDateString(),
                $date->copy()->endOfMonth()->toDateString(),
            ])->sum('bill_amount'),
            'readyToInvoice' => $readyToInvoice,
        ];
    }
}
