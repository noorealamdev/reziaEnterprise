<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\ServiceCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The business's financial state right now — billed/unbilled/outstanding/
 * profit, per-company cost & profit, invoice status, and upcoming agreement
 * deadlines. Single source of truth for these figures, shared by the
 * interactive Dashboard (resources/views/livewire/dashboard/dashboard.blade.php)
 * and the daily report email (App\Console\Commands\SendDailyReport) — the
 * VAT math, paid-only profit, and margin calculations here are easy to get
 * subtly wrong twice, so neither place reimplements them independently.
 */
class BusinessSnapshot
{
    public const BREAKDOWN_LIMIT = 8;

    /**
     * Every job entry not yet attached to an invoice.
     *
     * @return Collection<int, JobEntry>
     */
    public static function unbilled(): Collection
    {
        return JobEntry::whereNull('invoice_id')->with(['company', 'serviceCategory'])->get();
    }

    public static function unbilledTotal(): float
    {
        return (float) self::unbilled()->sum('bill_amount');
    }

    public static function unbilledCount(): int
    {
        return self::unbilled()->count();
    }

    /**
     * A manually-entered past bill has no job entries at all, so its amount
     * has to be added on separately — it's definitionally already billed
     * the moment it exists.
     */
    public static function billedTotal(): float
    {
        return (float) JobEntry::whereNotNull('invoice_id')->sum('bill_amount')
            + (float) Invoice::whereNotNull('manual_amount')->sum('manual_amount');
    }

    /**
     * Balance still owed per invoice — bill total (-VAT), minus any
     * pre-invoice advance, minus payments recorded against it since. A
     * Partially Paid invoice still owes something, so it's included
     * alongside Due ones; Paid invoices are excluded since their balance is
     * zero by definition of how status gets derived.
     */
    public static function outstandingTotal(): float
    {
        return (float) Invoice::whereIn('status', ['due', 'partial'])
            ->withSum('jobEntries as amount', 'bill_amount')
            ->withSum('jobEntries as advancePaid', 'company_adv_payment')
            ->withSum('payments as paidViaPayments', 'amount')
            ->get()
            ->sum(function (Invoice $invoice) {
                $amount = (float) ($invoice->manual_amount ?? $invoice->amount);
                $vatAmount = $invoice->vat_percent ? round($amount * (float) $invoice->vat_percent / 100, 2) : 0;

                return max(0, $amount - $vatAmount - (float) $invoice->advancePaid - (float) $invoice->paidViaPayments);
            });
    }

    /**
     * Always literally "this calendar month, paid" — a fixed pulse,
     * independent of any company/year/month filter elsewhere on the page.
     */
    public static function profitThisMonth(): float
    {
        return (float) JobEntry::whereHas('invoice', fn ($query) => $query->where('status', 'paid'))
            ->whereYear('entry_date', now()->year)
            ->whereMonth('entry_date', now()->month)
            ->sum('profit_amount');
    }

    /**
     * Same basis as profitThisMonth() but for the immediately preceding
     * calendar month — exists purely to show a month-over-month trend next
     * to it, never affected by any filter elsewhere on the page.
     */
    public static function profitLastMonth(): float
    {
        $lastMonth = now()->subMonthNoOverflow();

        return (float) JobEntry::whereHas('invoice', fn ($query) => $query->where('status', 'paid'))
            ->whereYear('entry_date', $lastMonth->year)
            ->whereMonth('entry_date', $lastMonth->month)
            ->sum('profit_amount');
    }

    /**
     * Unbilled work grouped by company + category (a company can have
     * several concurrent bills ready to go the same month) — flagged and
     * sorted with anything from an already-ended month first, since that's
     * the real risk of a bill actually getting missed.
     *
     * @return Collection<int, array{company: Company, category: ServiceCategory, count: int, total: float, oldestEntryDate: Carbon, isOverdue: bool}>
     */
    public static function readyToInvoice(): Collection
    {
        $currentMonthStart = now()->startOfMonth();

        return self::unbilled()
            ->groupBy(fn (JobEntry $entry) => $entry->company_id.'-'.$entry->service_category_id)
            ->map(function ($entries) use ($currentMonthStart) {
                $oldestEntryDate = $entries->min('entry_date');

                return [
                    'company' => $entries->first()->company,
                    'category' => $entries->first()->serviceCategory,
                    'count' => $entries->count(),
                    'total' => $entries->sum('bill_amount'),
                    'oldestEntryDate' => $oldestEntryDate,
                    'isOverdue' => $oldestEntryDate->lt($currentMonthStart),
                ];
            })
            ->filter(fn ($group) => $group['total'] > 0)
            ->sort(fn ($a, $b) => ($b['isOverdue'] <=> $a['isOverdue']) ?: ($b['total'] <=> $a['total']))
            ->values();
    }

    /**
     * A single at-a-glance breakdown of where every invoice stands — Paid,
     * Unpaid (Due + Partially Paid together, since both still owe money),
     * Signed (a signed copy is on file, independent of payment status), and
     * Not Invoiced (job entries with no invoice yet at all). The categories
     * overlap (Signed isn't mutually exclusive with Paid/Unpaid) rather than
     * summing to one whole, so this is a set of filtered counts, not a
     * stacked chart.
     *
     * @return Collection<int, array{label: string, statusValue: string, count: int, amount: float, color: string}>
     */
    public static function invoiceOverview(): Collection
    {
        return collect([
            [
                'label' => 'Paid',
                'statusValue' => 'paid',
                'count' => Invoice::where('status', 'paid')->count(),
                'amount' => (float) JobEntry::whereHas('invoice', fn ($query) => $query->where('status', 'paid'))->sum('bill_amount'),
                'color' => 'bg-emerald-500',
            ],
            [
                'label' => 'Unpaid',
                'statusValue' => 'unpaid',
                'count' => Invoice::whereIn('status', ['due', 'partial'])->count(),
                'amount' => (float) JobEntry::whereHas('invoice', fn ($query) => $query->whereIn('status', ['due', 'partial']))->sum('bill_amount'),
                'color' => 'bg-rose-500',
            ],
            [
                'label' => 'Signed',
                'statusValue' => 'signed',
                'count' => Invoice::whereNotNull('signed_copy_path')->count(),
                'amount' => (float) JobEntry::whereHas('invoice', fn ($query) => $query->whereNotNull('signed_copy_path'))->sum('bill_amount'),
                'color' => 'bg-sky-500',
            ],
            [
                'label' => 'Not Invoiced',
                'statusValue' => 'unbilled',
                'count' => JobEntry::whereNull('invoice_id')->count(),
                'amount' => (float) JobEntry::whereNull('invoice_id')->sum('bill_amount'),
                'color' => 'bg-slate-400',
            ],
        ]);
    }

    /**
     * Every agreement that's already expired or expiring within the same
     * lead time the email alert uses (`reports.agreement_deadline_alert_days`).
     * Already-expired agreements sort first (most overdue), then
     * soonest-to-expire.
     *
     * @return Collection<int, array{agreement: CompanyAgreement, daysRemaining: int, isExpired: bool}>
     */
    public static function agreementDeadlines(int $limit = self::BREAKDOWN_LIMIT): Collection
    {
        $alertCutoff = now()->addDays((int) config('reports.agreement_deadline_alert_days'))->toDateString();

        return CompanyAgreement::with('company')
            ->whereDate('end_date', '<=', $alertCutoff)
            ->orderBy('end_date')
            ->limit($limit)
            ->get()
            ->map(fn (CompanyAgreement $agreement) => [
                'agreement' => $agreement,
                'daysRemaining' => (int) round(now()->diffInDays($agreement->end_date, absolute: true)),
                'isExpired' => $agreement->is_expired,
            ]);
    }

    /**
     * The one comprehensive table of every factory's financial scenario —
     * what it still owes to be billed (all-time, never filtered — this is
     * "right now" state, not a historical figure), and what it billed/cost/
     * profited within the given year/month/company scope (unfiltered —
     * every year/month — when left blank). Broken down by company when
     * every company is in scope, or by category once a single company is
     * selected.
     *
     * Two profit figures, side by side, same convention the removed Cost &
     * Profit Report page used:
     *   - "All Work" — billed minus cost for every entry in scope, whether
     *     or not the client has paid yet. The honest economic picture.
     *   - "Paid Only" — only entries whose invoice is actually marked Paid.
     *     Cash in hand, not just work done.
     *
     * @return array{companies: Collection<int, Company>, selectedCompany: ?Company, availableYears: Collection<int, int>, monthOptions: Collection<int, string>, totalBilled: float, totalCost: float, totalProfitAllWork: float, marginAllWork: float, totalProfitPaid: float, marginPaid: float, rows: Collection<int, array<string, mixed>>, byCompany: bool}
     */
    public static function companiesFinancials(string $companyFilter = '', string $yearFilter = '', string $monthFilter = ''): array
    {
        $companies = Company::orderBy('name')->get();
        $selectedCompany = $companyFilter ? $companies->firstWhere('id', (int) $companyFilter) : null;
        $categories = $selectedCompany ? ServiceCategory::orderBy('sort_order')->get() : collect();

        $companyScoped = JobEntry::query()
            ->when($companyFilter, fn ($query) => $query->where('company_id', $companyFilter));

        // Offered years always reflect the whole history for this company
        // scope, not just what the current year/month filter leaves behind.
        $availableYears = (clone $companyScoped)->select('entry_date')->distinct()->pluck('entry_date')
            ->map(fn ($date) => $date->year)->unique()->sortDesc()->values();

        // whereYear()/whereMonth() compile to each database's own portable
        // syntax (unlike a raw YEAR()/MONTH() expression), so this works
        // against SQLite in tests the same as MySQL in production.
        $entries = (clone $companyScoped)
            ->when($yearFilter, fn ($query) => $query->whereYear('entry_date', $yearFilter))
            ->when($monthFilter, fn ($query) => $query->whereMonth('entry_date', $monthFilter));

        // cost_amount + shipment_tiffin_cost (Loading Unloading only —
        // feeding labourers on the shipment, never billed to the factory) —
        // a real cost incurred, so it belongs in "Cost incurred" too.
        $totals = (clone $entries)->selectRaw('SUM(bill_amount) as billed, SUM(cost_amount + COALESCE(shipment_tiffin_cost, 0)) as cost')->first();
        $totalBilled = (float) ($totals->billed ?? 0);
        $totalCost = (float) ($totals->cost ?? 0);
        $totalProfitAllWork = $totalBilled - $totalCost;

        $paidEntries = (clone $entries)->whereHas('invoice', fn ($query) => $query->where('status', 'paid'));

        $paidTotals = (clone $paidEntries)->selectRaw('SUM(profit_amount) as profit, SUM(bill_amount) as billed')->first();
        $totalProfitPaid = (float) ($paidTotals->profit ?? 0);
        $totalPaidBilled = (float) ($paidTotals->billed ?? 0);

        $groupBy = $selectedCompany ? 'service_category_id' : 'company_id';
        $relation = $selectedCompany ? 'serviceCategory' : 'company';

        // Unbilled is always all-time, independent of the year/month filter
        // above — "what still needs billing right now" isn't a historical
        // figure.
        $unbilledByGroup = (clone $companyScoped)
            ->whereNull('invoice_id')
            ->select($groupBy)
            ->selectRaw('SUM(bill_amount) as total')
            ->groupBy($groupBy)
            ->pluck('total', $groupBy);

        // Aggregated by the database (GROUP BY), not by loading every entry
        // as a hydrated model and reducing in PHP.
        $allWorkByGroup = (clone $entries)
            ->select($groupBy)
            ->selectRaw('SUM(bill_amount) as billed, SUM(cost_amount + COALESCE(shipment_tiffin_cost, 0)) as cost')
            ->groupBy($groupBy)
            ->with($relation)
            ->get()
            ->keyBy($groupBy);

        // cost here must match profit_amount's own definition (which already
        // subtracts shipment_tiffin_cost) or Billed - Cost would disagree
        // with the Profit (Paid) figure shown right beside it.
        $paidByGroup = (clone $paidEntries)
            ->select($groupBy)
            ->selectRaw('SUM(bill_amount) as billed, SUM(profit_amount) as profit')
            ->groupBy($groupBy)
            ->get()
            ->keyBy($groupBy);

        // Every company/category with EITHER unbilled work OR any billed
        // activity in scope gets a row — nothing going on at all stays
        // hidden, and no row is ever truncated or silently missing.
        $rows = $allWorkByGroup->keys()->merge($unbilledByGroup->keys())->unique()
            ->map(function ($id) use ($allWorkByGroup, $paidByGroup, $unbilledByGroup, $selectedCompany, $companies, $categories) {
                $workRow = $allWorkByGroup->get($id);
                $paidRow = $paidByGroup->get($id);

                $company = $selectedCompany ? null : ($workRow?->company ?? $companies->firstWhere('id', $id));
                $label = $selectedCompany
                    ? ($workRow?->serviceCategory?->name ?? $categories->firstWhere('id', $id)?->name ?? 'Unknown')
                    : ($company?->name ?? 'Unknown');

                $billed = (float) ($workRow?->billed ?? 0);
                $cost = (float) ($workRow?->cost ?? 0);
                $profitAllWork = $billed - $cost;
                $paidBilled = (float) ($paidRow?->billed ?? 0);
                $profitPaid = (float) ($paidRow?->profit ?? 0);

                return [
                    'id' => $id,
                    'label' => $label,
                    'company' => $company,
                    'unbilled' => (float) ($unbilledByGroup[$id] ?? 0),
                    'billed' => $billed,
                    'cost' => $cost,
                    'profitAllWork' => $profitAllWork,
                    'marginAllWork' => $billed > 0 ? round($profitAllWork / $billed * 100, 1) : 0.0,
                    'profitPaid' => $profitPaid,
                    'marginPaid' => $paidBilled > 0 ? round($profitPaid / $paidBilled * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('profitAllWork')
            ->values();

        return [
            'companies' => $companies,
            'selectedCompany' => $selectedCompany,
            'availableYears' => $availableYears,
            'monthOptions' => collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(2000, $m, 1)->format('F')]),
            'totalBilled' => $totalBilled,
            'totalCost' => $totalCost,
            'totalProfitAllWork' => $totalProfitAllWork,
            'marginAllWork' => $totalBilled > 0 ? round($totalProfitAllWork / $totalBilled * 100, 1) : 0.0,
            'totalProfitPaid' => $totalProfitPaid,
            'marginPaid' => $totalPaidBilled > 0 ? round($totalProfitPaid / $totalPaidBilled * 100, 1) : 0.0,
            'rows' => $rows,
            'byCompany' => ! $selectedCompany,
        ];
    }
}
