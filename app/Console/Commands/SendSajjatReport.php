<?php

namespace App\Console\Commands;

use App\Mail\SajjatReportMail;
use App\Models\SajjatTransaction;
use App\Models\User;
use App\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendSajjatReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:sajjat';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Email Sajjat's daily wallet report (today's activity and balances) to every active Super Admin";

    public function handle(): int
    {
        // Nothing to report until Sajjat's ledger has been used at all —
        // an all-zero email every evening before then would just be noise.
        if (! SajjatTransaction::query()->exists()) {
            $this->info('No Sajjat entries recorded yet — skipping Sajjat report.');

            return self::SUCCESS;
        }

        $recipients = User::where('role', UserRole::SuperAdmin)->where('is_active', true)->get();

        if ($recipients->isEmpty()) {
            $this->info('No active Super Admins to notify — skipping Sajjat report.');

            return self::SUCCESS;
        }

        $date = now();
        $data = $this->reportData($date);

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(new SajjatReportMail($date, $data));
        }

        $this->info("Sajjat report sent to {$recipients->count()} Super Admin(s).");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function reportData(Carbon $date): array
    {
        $today = SajjatTransaction::whereDate('transaction_date', $date->toDateString())
            ->orderBy('id')
            ->get();

        $monthEntries = SajjatTransaction::whereBetween('transaction_date', [
            $date->copy()->startOfMonth()->toDateString(),
            $date->copy()->endOfMonth()->toDateString(),
        ])->get();

        $sum = fn ($entries, string $type) => (float) $entries->where('type', $type)->sum('amount');

        $balances = SajjatTransaction::balances();

        return [
            'wallets' => SajjatTransaction::WALLETS,
            'balances' => $balances,
            'totalBalance' => round(array_sum($balances), 2),
            'todayEntries' => $today,
            'todayTopUps' => $sum($today, SajjatTransaction::TYPE_TOP_UP),
            'todaySpent' => $sum($today, SajjatTransaction::TYPE_EXPENSE),
            'monthTopUps' => $sum($monthEntries, SajjatTransaction::TYPE_TOP_UP),
            'monthSpent' => $sum($monthEntries, SajjatTransaction::TYPE_EXPENSE),
        ];
    }
}
