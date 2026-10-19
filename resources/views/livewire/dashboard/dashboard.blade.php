<?php

use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\ServiceCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component
{
    private const TREND_MONTHS = 6;

    private const EXPENSE_TREND_DAYS = 14;

    private const BREAKDOWN_LIMIT = 8;

    #[Url(as: 'p_company', history: true)]
    public string $profitCompanyFilter = '';

    #[Url(as: 'p_year', history: true)]
    public string $profitYearFilter = '';

    #[Url(as: 'p_month', history: true)]
    public string $profitMonthFilter = '';

    public function mount(): void
    {
        if ($this->profitYearFilter === '') {
            $this->profitYearFilter = (string) now()->year;
        }

        if ($this->profitMonthFilter === '') {
            $this->profitMonthFilter = (string) now()->month;
        }
    }

    public function updatingProfitYearFilter(): void
    {
        // A month only makes sense within a chosen year — clear it if the
        // year changes so the two never disagree.
        $this->profitMonthFilter = '';
    }

    public function with(): array
    {
        $unbilled = JobEntry::whereNull('invoice_id')->with(['company', 'serviceCategory'])->get();

        // A company/category is billed monthly — the risk of actually
        // *missing* a bill is unbilled work sitting in a month that has
        // already ended, not this month's still-accumulating entries (that
        // month isn't over yet, so nothing to send for it). Flagged and
        // sorted first so a straggler from a prior month can never quietly
        // scroll off the bottom of this list.
        $currentMonthStart = now()->startOfMonth();

        // Invoices are now generated per company *and* category, so a
        // company can have several concurrent bills ready to go the same
        // month — group by both instead of company alone.
        $readyToInvoice = $unbilled
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

        // A manually-entered past bill has no job entries at all, so its
        // amount has to be added on separately — it's definitionally
        // already billed the moment it exists.
        $billedTotal = (float) JobEntry::whereNotNull('invoice_id')->sum('bill_amount')
            + (float) Invoice::whereNotNull('manual_amount')->sum('manual_amount');

        // Balance still owed per invoice — bill total (-VAT), minus any
        // pre-invoice advance, minus payments recorded against it since. A
        // Partially Paid invoice still owes something, so it's included
        // alongside Due ones; Paid invoices are excluded since their balance
        // is zero by definition of how status gets derived.
        $outstandingTotal = Invoice::whereIn('status', ['due', 'partial'])
            ->withSum('jobEntries as amount', 'bill_amount')
            ->withSum('jobEntries as advancePaid', 'company_adv_payment')
            ->withSum('payments as paidViaPayments', 'amount')
            ->get()
            ->sum(function (Invoice $invoice) {
                $amount = (float) ($invoice->manual_amount ?? $invoice->amount);
                $vatAmount = $invoice->vat_percent ? round($amount * (float) $invoice->vat_percent / 100, 2) : 0;

                return max(0, $amount - $vatAmount - (float) $invoice->advancePaid - (float) $invoice->paidViaPayments);
            });

        // Always literally "this calendar month, paid" — a fixed pulse,
        // unrelated to the Companies table's own Year/Month filter below.
        $profitThisMonth = (float) JobEntry::whereHas('invoice', fn ($query) => $query->where('status', 'paid'))
            ->whereYear('entry_date', now()->year)
            ->whereMonth('entry_date', now()->month)
            ->sum('profit_amount');

        // Tiffin is billed as one package per department/day, not per
        // ingredient — so entries for the same company/department today are
        // collapsed into a single row instead of one per item (Banana/Egg/
        // Bread), matching how Tiffin already reads everywhere else.
        $todayEntries = JobEntry::whereDate('entry_date', now()->toDateString())
            ->with(['company', 'serviceCategory', 'tiffinDepartment'])
            ->orderByDesc('id')
            ->get()
            // tiffin_department_id is also reused for Simba Fashion's
            // per-unit Daily Basic Labour entries — only an actual Tiffin
            // entry collapses into the department batch row below.
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
                    'items' => $isTiffinBatch ? $group->pluck('supply_type')->unique()->sort()->implode(', ') : null,
                    'billAmount' => (float) $group->sum('bill_amount'),
                    'sortId' => $first->id,
                ];
            })
            ->sortByDesc('sortId')
            ->values();

        return [
            'companyCount' => Company::count(),
            'activeCompanyCount' => Company::where('is_active', true)->count(),
            'unbilledTotal' => (float) $unbilled->sum('bill_amount'),
            'unbilledCount' => $unbilled->count(),
            'billedTotal' => $billedTotal,
            'outstandingTotal' => (float) $outstandingTotal,
            'profitThisMonth' => $profitThisMonth,
            'readyToInvoice' => $readyToInvoice,
            'todayEntries' => $todayEntries,
            'monthlyTrend' => $this->monthlyTrend(),
            'dailyExpenseTrend' => $this->dailyExpenseTrend(),
            'invoiceOverview' => $this->invoiceOverview(),
            'agreementDeadlines' => $this->agreementDeadlines(),
            ...$this->companiesFinancials(),
        ];
    }

    /**
     * Daily expense totals for the last two weeks (including today), so a
     * sudden spike or a quiet stretch is visible at a glance — the same
     * "grouped in PHP by a real Carbon property, not a raw SQL date
     * function" convention as monthlyTrend(), so this stays portable
     * between MySQL (production) and SQLite (tests).
     *
     * @return Collection<int, array{label: string, total: float, pct: float}>
     */
    private function dailyExpenseTrend(): Collection
    {
        $days = collect(range(self::EXPENSE_TREND_DAYS - 1, 0))
            ->map(fn (int $i) => now()->subDays($i)->startOfDay());

        $expensesByDay = Expense::where('expense_date', '>=', $days->first()->toDateString())
            ->get(['expense_date', 'amount'])
            ->groupBy(fn (Expense $expense) => $expense->expense_date->format('Y-m-d'));

        $rows = $days->map(function (Carbon $day) use ($expensesByDay) {
            $total = (float) $expensesByDay->get($day->format('Y-m-d'), collect())->sum('amount');

            return [
                'label' => $day->format('d M'),
                'total' => $total,
            ];
        });

        $max = max(1.0, (float) $rows->max('total'));

        return $rows->map(fn ($row) => [
            ...$row,
            'pct' => $this->barPercent($row['total'], $max),
        ]);
    }

    /**
     * A single at-a-glance breakdown of where every invoice stands — Paid,
     * Unpaid (Due + Partially Paid together, since both still owe money),
     * Signed (a signed copy is on file — independent of payment status, an
     * invoice can be signed and still unpaid), and Not Invoiced (job entries
     * with no invoice yet at all). Each entry links straight through to Bill
     * Statement filtered to that exact status, so the office can actually
     * open and view those bills rather than just eyeball a total — the
     * categories overlap (Signed isn't mutually exclusive with Paid/Unpaid)
     * rather than summing to one whole, so this is a set of filtered counts,
     * not a stacked chart.
     *
     * @return Collection<int, array{label: string, statusValue: string, count: int, amount: float, color: string}>
     */
    private function invoiceOverview(): Collection
    {
        $rows = collect([
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

        return $rows;
    }

    /**
     * Every agreement that's already expired or expiring within the same
     * lead time the email alert uses (`reports.agreement_deadline_alert_days`)
     * — an in-app view of the same thing the Super Admin email covers, so
     * the deadline is visible here even on a day the email never went out
     * (e.g. SMTP isn't configured yet on a fresh deploy). Already-expired
     * agreements sort first (most overdue), then soonest-to-expire.
     *
     * @return Collection<int, array{agreement: CompanyAgreement, daysRemaining: int, isExpired: bool}>
     */
    private function agreementDeadlines(): Collection
    {
        $alertCutoff = now()->addDays((int) config('reports.agreement_deadline_alert_days'))->toDateString();

        return CompanyAgreement::with('company')
            ->whereDate('end_date', '<=', $alertCutoff)
            ->orderBy('end_date')
            ->limit(self::BREAKDOWN_LIMIT)
            ->get()
            ->map(fn (CompanyAgreement $agreement) => [
                'agreement' => $agreement,
                'daysRemaining' => (int) round(now()->diffInDays($agreement->end_date, absolute: true)),
                'isExpired' => $agreement->is_expired,
            ]);
    }

    /**
     * Billed vs unbilled totals for each of the last several months, scaled
     * to percentages so the view can render bar heights without touching
     * the underlying figures.
     *
     * @return Collection<int, array{label: string, billed: float, unbilled: float, total: float, billedPct: float, unbilledPct: float}>
     */
    private function monthlyTrend(): Collection
    {
        $months = collect(range(self::TREND_MONTHS - 1, 0))
            ->map(fn (int $i) => now()->subMonths($i)->startOfMonth());

        // Grouped in PHP by a real Carbon property rather than a raw SQL
        // date function, so this stays portable between MySQL (production)
        // and SQLite (tests) — the same convention used for Bill Statement
        // and Daily Summary's own month grouping.
        $entriesByMonth = JobEntry::where('entry_date', '>=', $months->first()->toDateString())
            ->get(['entry_date', 'bill_amount', 'invoice_id'])
            ->groupBy(fn (JobEntry $entry) => $entry->entry_date->format('Y-m'));

        $rows = $months->map(function (Carbon $month) use ($entriesByMonth) {
            $entries = $entriesByMonth->get($month->format('Y-m'), collect());
            $billed = (float) $entries->whereNotNull('invoice_id')->sum('bill_amount');
            $unbilled = (float) $entries->whereNull('invoice_id')->sum('bill_amount');

            return [
                'label' => $month->format('M Y'),
                'billed' => $billed,
                'unbilled' => $unbilled,
                'total' => $billed + $unbilled,
            ];
        });

        $max = max(1.0, (float) $rows->max('total'));

        return $rows->map(fn ($row) => [
            ...$row,
            'billedPct' => $this->barPercent($row['billed'], $max),
            'unbilledPct' => $this->barPercent($row['unbilled'], $max),
        ]);
    }

    /**
     * The one comprehensive table of every factory's financial scenario —
     * what it still owes to be billed (all-time, never filtered — this is
     * "right now" state, not a historical figure), and what it billed/cost/
     * profited within the chosen Year/Month/Company scope (defaults to the
     * current month, set in mount()). Broken down by company when every
     * company is in view, or by category once a single company is selected
     * (a company breakdown would just be one row at that point).
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
    private function companiesFinancials(): array
    {
        $companies = Company::orderBy('name')->get();
        $selectedCompany = $this->profitCompanyFilter
            ? $companies->firstWhere('id', (int) $this->profitCompanyFilter)
            : null;
        $categories = $selectedCompany ? ServiceCategory::orderBy('sort_order')->get() : collect();

        $companyScoped = JobEntry::query()
            ->when($this->profitCompanyFilter, fn ($query) => $query->where('company_id', $this->profitCompanyFilter));

        // Offered years always reflect the whole history for this company
        // scope, not just what the current year/month filter leaves behind
        // — same convention as Bill Statement/Daily Summary's dropdowns.
        $availableYears = (clone $companyScoped)->select('entry_date')->distinct()->pluck('entry_date')
            ->map(fn ($date) => $date->year)->unique()->sortDesc()->values();

        // whereYear()/whereMonth() compile to each database's own portable
        // syntax (unlike a raw YEAR()/MONTH() expression), so this still
        // works against SQLite in tests the same as MySQL in production.
        $entries = (clone $companyScoped)
            ->when($this->profitYearFilter, fn ($query) => $query->whereYear('entry_date', $this->profitYearFilter))
            ->when($this->profitMonthFilter, fn ($query) => $query->whereMonth('entry_date', $this->profitMonthFilter));

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

        // Unbilled is always all-time, independent of the Year/Month filter
        // above — "what still needs billing right now" isn't a historical
        // figure, same convention as the Unbilled stat card and Ready to
        // Invoice further up this page.
        $unbilledByGroup = (clone $companyScoped)
            ->whereNull('invoice_id')
            ->select($groupBy)
            ->selectRaw('SUM(bill_amount) as total')
            ->groupBy($groupBy)
            ->pluck('total', $groupBy);

        // Aggregated by the database (GROUP BY), not by loading every entry
        // as a hydrated model and reducing in PHP — this scans the whole
        // job_entries table otherwise, fine at a handful of rows and
        // ruinous once real usage builds up a real history.
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
        // hidden, and (Cost & Profit Report was removed) this is now the
        // one place a factory's whole scenario lives, so no row is ever
        // truncated or silently missing.
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

    /**
     * A value's share of $max as a percentage, floored at 2% once it's
     * nonzero so a small-but-real amount never renders as an invisible
     * sliver next to a much larger one.
     */
    private function barPercent(float $value, float $max): float
    {
        if ($value <= 0) {
            return 0.0;
        }

        return max(2.0, round($value / $max * 100, 2));
    }
}; ?>

<div class="space-y-6">
    @can('dashboard.view')
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card
            label="Unbilled"
            :value="number_format($unbilledTotal, 2)"
            :hint="$unbilledCount.' '.Str::plural('entry', $unbilledCount).' not yet invoiced'"
            :href="Gate::allows('bill_statement.view') ? route('bill-statement.index', ['status' => 'unbilled']) : null"
        >
            <x-slot:icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="5" y="4" width="14" height="17" rx="2" />
                    <rect x="9" y="2.5" width="6" height="3" rx="1" />
                    <path d="M8.5 10h7M8.5 13.5h7M8.5 17h4" />
                </svg>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card
            label="Billed"
            :value="number_format($billedTotal, 2)"
            hint="Already invoiced, all-time"
            :href="Gate::allows('bill_statement.view') ? route('bill-statement.index', ['status' => 'billed']) : null"
        >
            <x-slot:icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M8.5 12.5l2.5 2.5 5-5" />
                </svg>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card
            label="Outstanding (Due)"
            :value="number_format($outstandingTotal, 2)"
            hint="Billed, awaiting payment"
            :href="Gate::allows('bill_statement.view') ? route('bill-statement.index', ['status' => 'unpaid']) : null"
        >
            <x-slot:icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 3h12v18l-2.5-1.5L13 21l-2.5-1.5L8 21l-2-1.5V3z" />
                    <path d="M9 8h6M9 11.5h6M9 15h4" />
                </svg>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card
            label="Profit This Month"
            :value="number_format($profitThisMonth, 2)"
            hint="Only counts what's actually been paid so far"
            :href="Gate::allows('dashboard.view_profit') ? '#companies-cost-profit' : null"
        >
            <x-slot:icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M11 3H5a2 2 0 0 0-2 2v6l10 10 8-8L11 3z" />
                    <circle cx="7.5" cy="7.5" r="1.25" />
                </svg>
            </x-slot:icon>
        </x-stat-card>
    </div>

    <div>
        <h2 class="px-1 text-base font-semibold text-slate-900 dark:text-white">Pending Bills</h2>
        <p class="mb-3 px-1 text-xs text-slate-400 dark:text-slate-500">Everything still waiting on an invoice, or waiting to be paid.</p>
    </div>

    <div>
        <h3 class="mb-2 px-1 text-sm font-semibold text-slate-700 dark:text-slate-300">Ready to Invoice</h3>
        <p class="mb-2 px-1 text-xs text-slate-400 dark:text-slate-500">Work that's been done but hasn't been turned into an invoice yet.</p>

        @if ($readyToInvoice->isEmpty())
            <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-500 shadow-sm dark:border-slate-800 dark:bg-slate-800 dark:text-slate-400">
                You're all caught up — every entry has been invoiced.
            </div>
        @else
            <div class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white dark:divide-slate-700/50 dark:border-slate-800 dark:bg-slate-800">
                @foreach ($readyToInvoice as $group)
                    <div class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <a href="{{ route('companies.show', $group['company']) }}" wire:navigate class="text-sm font-semibold text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">
                                {{ $group['company']->name }}
                            </a>
                            <x-badge color="brand" class="ml-1">{{ $group['category']->name }}</x-badge>
                            @if ($group['isOverdue'])
                                <x-badge color="red" class="ml-1">Since {{ $group['oldestEntryDate']->format('M Y') }}</x-badge>
                            @endif
                            <p class="text-xs text-slate-400 dark:text-slate-500">{{ $group['count'] }} {{ Str::plural('entry', $group['count']) }} unbilled</p>
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ number_format($group['total'], 2) }}</span>
                            @can('invoices.create')
                                <x-secondary-button :href="route('invoices.create', ['company' => $group['company']->id, 'category' => $group['category']->id])" wire:navigate class="!text-xs">
                                    Generate Invoice
                                </x-secondary-button>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-800">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300">Invoice Overview</h3>
                <p class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">Invoices already sent to the factory, broken down by whether they've paid.</p>
            </div>
            @can('bill_statement.view')
                <span class="text-xs text-slate-400 dark:text-slate-500">Click a card to view those bills</span>
            @endcan
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($invoiceOverview as $row)
                @can('bill_statement.view')
                    <a
                        href="{{ route('bill-statement.index', ['status' => $row['statusValue']]) }}"
                        wire:navigate
                        class="group flex flex-col gap-1 rounded-lg border border-slate-200 p-3 transition hover:border-brand-300 hover:shadow-sm dark:border-slate-700 dark:hover:border-brand-700"
                    >
                        <span class="flex items-center gap-1.5 text-xs font-medium text-slate-500 dark:text-slate-400">
                            <span class="h-2 w-2 shrink-0 rounded-full {{ $row['color'] }}"></span>
                            {{ $row['label'] }}
                        </span>
                        <span class="text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($row['amount'], 2) }}</span>
                        <span class="flex items-center justify-between text-xs text-slate-400 dark:text-slate-500">
                            <span>{{ $row['count'] }} {{ Str::plural($row['label'] === 'Not Invoiced' ? 'entry' : 'invoice', $row['count']) }}</span>
                            <span class="text-brand-600 opacity-0 transition group-hover:opacity-100 dark:text-brand-400">View &rarr;</span>
                        </span>
                    </a>
                @else
                    <div class="flex flex-col gap-1 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <span class="flex items-center gap-1.5 text-xs font-medium text-slate-500 dark:text-slate-400">
                            <span class="h-2 w-2 shrink-0 rounded-full {{ $row['color'] }}"></span>
                            {{ $row['label'] }}
                        </span>
                        <span class="text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($row['amount'], 2) }}</span>
                        <span class="text-xs text-slate-400 dark:text-slate-500">{{ $row['count'] }} {{ Str::plural($row['label'] === 'Not Invoiced' ? 'entry' : 'invoice', $row['count']) }}</span>
                    </div>
                @endcan
            @endforeach
        </div>
    </div>

    @can('dashboard.view_profit')
    <div id="companies-cost-profit" class="scroll-mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-800">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300">Companies — Cost &amp; Profit</h3>
                <p class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">
                    What every factory has billed, what it cost to deliver, and what's actually been earned.
                    <span class="font-medium text-emerald-600 dark:text-emerald-400">Green</span> is a profit,
                    <span class="font-medium text-red-600 dark:text-red-400">red</span> is a loss.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-select-input wire:model.live="profitCompanyFilter" class="w-full sm:w-56">
                    <option value="">All companies</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </x-select-input>

                <x-select-input wire:model.live="profitYearFilter" class="w-full sm:w-32">
                    <option value="">Every year</option>
                    @foreach ($availableYears as $year)
                        <option value="{{ $year }}">{{ $year }}</option>
                    @endforeach
                </x-select-input>

                <x-select-input wire:model.live="profitMonthFilter" class="w-full sm:w-40" :disabled="! $profitYearFilter">
                    <option value="">{{ $profitYearFilter ? 'Every month' : 'Pick a year first' }}</option>
                    @foreach ($monthOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select-input>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div>
                <p class="text-xs text-slate-500 dark:text-slate-400" title="Work billed regardless of payment status.">Total Billed</p>
                <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($totalBilled, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 dark:text-slate-400" title="Cost incurred regardless of payment status.">Total Cost</p>
                <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($totalCost, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 dark:text-slate-400" title="Billed minus cost, for every entry — whether or not the client has paid yet. The honest, full picture.">Profit — All Work</p>
                <p class="mt-1 text-lg font-semibold {{ $totalProfitAllWork >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                    {{ number_format($totalProfitAllWork, 2) }}
                    <span class="text-sm font-normal">({{ number_format($marginAllWork, 1) }}%)</span>
                </p>
            </div>
            <div>
                <p class="text-xs text-slate-500 dark:text-slate-400" title="Only counts invoices actually marked Paid — Due and Partially Paid bills aren't profit yet. Cash in hand.">Profit — Paid Only</p>
                <p class="mt-1 text-lg font-semibold {{ $totalProfitPaid >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                    {{ number_format($totalProfitPaid, 2) }}
                    <span class="text-sm font-normal">({{ number_format($marginPaid, 1) }}%)</span>
                </p>
            </div>
        </div>

        <div class="mt-5 border-t border-slate-100 pt-4 dark:border-slate-700/50">
            <h4 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                {{ $byCompany ? 'Every Company' : $selectedCompany->name.' — By Category' }}
            </h4>

            @if ($rows->isEmpty())
                <p class="py-4 text-center text-sm text-slate-400 dark:text-slate-500">No billing activity in this scope yet.</p>
            @else
                {{-- Every row shows, never truncated — scrolls once the list
                outgrows the panel instead of capping at a fixed count. --}}
                <div class="max-h-[36rem] overflow-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead class="sticky top-0 bg-white dark:bg-slate-800">
                            <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                <th class="px-3 py-2">{{ $byCompany ? 'Company' : 'Category' }}</th>
                                <th class="px-3 py-2 text-right">Unbilled</th>
                                <th class="px-3 py-2 text-right">Billed</th>
                                <th class="px-3 py-2 text-right">Cost</th>
                                <th class="px-3 py-2 text-right">Profit — All Work</th>
                                <th class="px-3 py-2 text-right">Profit — Paid Only</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-b border-slate-100 last:border-0 dark:border-slate-700/50">
                                    <td class="px-3 py-2 font-medium text-slate-700 dark:text-slate-300">
                                        @if ($byCompany)
                                            <a href="{{ route('companies.show', $row['company']) }}" wire:navigate class="hover:text-brand-600 dark:hover:text-brand-400">
                                                {{ $row['label'] }}
                                            </a>
                                        @else
                                            {{ $row['label'] }}
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right {{ $row['unbilled'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400 dark:text-slate-500' }}">
                                        {{ $row['unbilled'] > 0 ? number_format($row['unbilled'], 2) : '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-right text-slate-600 dark:text-slate-400">{{ number_format($row['billed'], 2) }}</td>
                                    <td class="px-3 py-2 text-right text-slate-600 dark:text-slate-400">{{ number_format($row['cost'], 2) }}</td>
                                    <td class="px-3 py-2 text-right font-medium {{ $row['profitAllWork'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                                        {{ number_format($row['profitAllWork'], 2) }}
                                        <span class="font-normal text-xs">({{ number_format($row['marginAllWork'], 1) }}%)</span>
                                    </td>
                                    <td class="px-3 py-2 text-right font-medium {{ $row['profitPaid'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                                        {{ number_format($row['profitPaid'], 2) }}
                                        <span class="font-normal text-xs">({{ number_format($row['marginPaid'], 1) }}%)</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
    @endcan

    <div>
        <h3 class="mb-2 px-1 text-sm font-semibold text-slate-700 dark:text-slate-300">Today's Activity</h3>

        @if ($todayEntries->isEmpty())
            <x-empty-state
                title="Nothing logged today yet"
                message="Add today's supplies as they happen."
            >
                @can('job_entries.create')
                    <x-slot:action>
                        <x-primary-button :href="route('job-entries.create')" wire:navigate>
                            New Entry
                        </x-primary-button>
                    </x-slot:action>
                @endcan
            </x-empty-state>
        @else
            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <table class="w-full min-w-[560px] text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                            <th class="px-4 py-2">Company</th>
                            <th class="px-4 py-2">Category</th>
                            <th class="px-4 py-2">Item</th>
                            <th class="px-4 py-2 text-right">Bill</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($todayEntries as $entry)
                            <tr class="border-b border-slate-100 last:border-0 dark:border-slate-700/50">
                                <td class="px-4 py-2 text-slate-700 dark:text-slate-300">{{ $entry->company->name }}</td>
                                <td class="px-4 py-2">
                                    <x-badge color="brand">{{ $entry->serviceCategory->name }}</x-badge>
                                </td>
                                <td class="px-4 py-2 text-slate-600 dark:text-slate-400">
                                    {{ $entry->label }}
                                    @if ($entry->items)
                                        <span class="block text-xs text-slate-400 dark:text-slate-500">{{ $entry->items }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right font-medium text-slate-800 dark:text-slate-200">{{ number_format($entry->billAmount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300">Billed vs Unbilled — Last {{ $monthlyTrend->count() }} Months</h3>
                <div class="flex items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-brand-500"></span>Billed</span>
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-amber-400"></span>Unbilled</span>
                </div>
            </div>

            <div class="flex h-48 items-end justify-between gap-2 sm:gap-3">
                @foreach ($monthlyTrend as $month)
                    <div class="flex h-full flex-1 flex-col items-center justify-end gap-2">
                        <div
                            class="flex w-full max-w-10 flex-1 flex-col-reverse overflow-hidden rounded-md bg-slate-100 dark:bg-slate-700/40"
                            title="{{ $month['label'] }} — Billed {{ number_format($month['billed'], 2) }} · Unbilled {{ number_format($month['unbilled'], 2) }}"
                        >
                            <div class="w-full bg-brand-500" style="height: {{ $month['billedPct'] }}%"></div>
                            <div class="w-full bg-amber-400" style="height: {{ $month['unbilledPct'] }}%"></div>
                        </div>
                        <span class="text-center text-[10px] leading-tight text-slate-500 dark:text-slate-400">{{ $month['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        @can('company_agreements.view')
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300">Agreement Deadlines</h3>
                    <a href="{{ route('company-agreements.index') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                        View all &rarr;
                    </a>
                </div>

                @if ($agreementDeadlines->isEmpty())
                    <p class="py-6 text-center text-sm text-slate-400 dark:text-slate-500">No agreements are expiring soon.</p>
                @else
                    <div class="divide-y divide-slate-100 dark:divide-slate-700/50">
                        @foreach ($agreementDeadlines as $row)
                            <div class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                                <div class="min-w-0">
                                    <a href="{{ route('companies.show', $row['agreement']->company) }}" wire:navigate class="text-sm font-medium text-slate-800 hover:text-brand-600 dark:text-slate-200 dark:hover:text-brand-400">
                                        {{ $row['agreement']->company->name }}
                                    </a>
                                    <span class="text-sm text-slate-500 dark:text-slate-400">— {{ $row['agreement']->title }}</span>
                                    <p class="text-xs text-slate-400 dark:text-slate-500">Ends {{ $row['agreement']->end_date->format('d M Y') }}</p>
                                </div>
                                @if ($row['isExpired'])
                                    <x-badge color="red">Expired {{ $row['daysRemaining'] }} {{ Str::plural('day', $row['daysRemaining']) }} ago</x-badge>
                                @else
                                    <x-badge color="amber">{{ $row['daysRemaining'] }} {{ Str::plural('day', $row['daysRemaining']) }} left</x-badge>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endcan
    </div>

    @can('expenses.view')
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300">Expenses — Last {{ $dailyExpenseTrend->count() }} Days</h3>
                <span class="text-xs text-slate-500 dark:text-slate-400">Total: {{ number_format($dailyExpenseTrend->sum('total'), 2) }}</span>
            </div>

            @if ($dailyExpenseTrend->sum('total') <= 0)
                <p class="py-8 text-center text-sm text-slate-400 dark:text-slate-500">No expenses recorded in this window.</p>
            @else
                <div class="flex h-40 items-end justify-between gap-1 sm:gap-2">
                    @foreach ($dailyExpenseTrend as $day)
                        <div class="flex h-full flex-1 flex-col items-center justify-end gap-2">
                            <div
                                class="flex w-full max-w-8 flex-1 flex-col-reverse overflow-hidden rounded-md bg-slate-100 dark:bg-slate-700/40"
                                title="{{ $day['label'] }} — {{ number_format($day['total'], 2) }}"
                            >
                                <div class="w-full bg-brand-500" style="height: {{ $day['pct'] }}%"></div>
                            </div>
                            <span class="text-center text-[10px] leading-tight text-slate-500 dark:text-slate-400">{{ $day['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endcan
    @else
        <x-empty-state
            title="Nothing to show yet"
            message="Ask your Super Admin to grant you dashboard access."
        />
    @endcan

    <div class="flex flex-wrap gap-4 px-1 text-xs text-slate-400 dark:text-slate-500">
        <span>{{ $companyCount }} {{ Str::plural('company', $companyCount) }} total</span>
        <span>{{ $activeCompanyCount }} active</span>
    </div>
</div>
