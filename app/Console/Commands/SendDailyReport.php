<?php

namespace App\Console\Commands;

use App\Mail\DailyReportMail;
use App\Models\Company;
use App\Models\JobEntry;
use App\Models\User;
use App\Support\BusinessSnapshot;
use App\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
     * Mirrors the Dashboard's own sections (resources/views/livewire/dashboard/dashboard.blade.php)
     * so the daily email gives the same full picture as logging in and
     * looking at it — the figures come from App\Support\BusinessSnapshot,
     * the same source the Dashboard itself reads, so the two can never
     * quietly disagree.
     *
     * @return array<string, mixed>
     */
    private function reportData(Carbon $date): array
    {
        $unbilled = BusinessSnapshot::unbilled();
        $todayEntries = JobEntry::whereDate('entry_date', $date->toDateString())
            ->with(['company', 'serviceCategory', 'tiffinDepartment'])
            ->get();

        // Companies — Cost & Profit, scoped to the report's own month. In the
        // first days of a month nothing is recorded yet, so it shows last
        // month rather than a table of zeros.
        $companiesMonth = $date->copy();
        $companiesRows = $this->companyRows($companiesMonth);

        if ($companiesRows->isEmpty()) {
            $companiesMonth = $date->copy()->startOfMonth()->subMonthNoOverflow();
            $companiesRows = $this->companyRows($companiesMonth);
        }

        return [
            'companyCount' => Company::count(),
            'todayEntryCount' => $todayEntries->count(),
            'todayTotal' => (float) $todayEntries->sum('bill_amount'),
            'todayActivity' => $this->groupTodayActivity($todayEntries),
            'unbilledTotal' => (float) $unbilled->sum('bill_amount'),
            'unbilledCount' => $unbilled->count(),
            'billedTotal' => BusinessSnapshot::billedTotal(),
            'outstandingTotal' => BusinessSnapshot::outstandingTotal(),
            'profitAndLoss' => BusinessSnapshot::profitAndLoss(),
            'billsNotSubmitted' => BusinessSnapshot::billsNotSubmitted(),
            'billsDueSoon' => BusinessSnapshot::billsDueSoon(),
            'readyToInvoice' => BusinessSnapshot::readyToInvoice(),
            'invoiceOverview' => BusinessSnapshot::invoiceOverview(),
            'agreementDeadlines' => BusinessSnapshot::agreementDeadlines(),
            'companiesRows' => $companiesRows,
            'companiesMonthLabel' => $companiesMonth->format('F Y'),
        ];
    }

    /**
     * Only companies with work in that month. The Dashboard's table also lists
     * companies whose only activity is older unbilled work.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function companyRows(Carbon $month): Collection
    {
        return BusinessSnapshot::companiesFinancials('', (string) $month->year, (string) $month->month)['rows']
            ->filter(fn (array $row) => $row['billed'] > 0 || $row['cost'] > 0)
            ->values();
    }

    /**
     * Same Tiffin-batch collapsing the Dashboard's "Today's Activity" panel
     * uses — one row per department/day rather than one per ingredient —
     * so the email reads the same way the in-app view does instead of
     * spamming a dozen near-duplicate lines for a single Tiffin delivery.
     *
     * @param  Collection<int, JobEntry>  $todayEntries
     * @return Collection<int, object>
     */
    private function groupTodayActivity(Collection $todayEntries): Collection
    {
        return $todayEntries
            ->groupBy(fn (JobEntry $entry) => ($entry->tiffin_department_id && $entry->serviceCategory->name === 'Tiffin')
                ? "tiffin-{$entry->company_id}-{$entry->tiffin_department_id}"
                : "single-{$entry->id}")
            ->map(function ($group) {
                $first = $group->first();
                $isTiffinBatch = $first->tiffin_department_id && $first->serviceCategory->name === 'Tiffin';

                return (object) [
                    'company' => $first->company,
                    'serviceCategory' => $first->serviceCategory,
                    'label' => $isTiffinBatch ? $first->tiffinDepartment->name : $first->supply_type,
                    'billAmount' => (float) $group->sum('bill_amount'),
                    'sortId' => $first->id,
                ];
            })
            ->sortByDesc('sortId')
            ->values();
    }
}
