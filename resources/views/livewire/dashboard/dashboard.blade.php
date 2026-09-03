<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\JobEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component
{
    private const TREND_MONTHS = 6;

    private const BREAKDOWN_LIMIT = 8;

    #[Url(as: 'p_company', history: true)]
    public string $profitCompanyFilter = '';

    #[Url(as: 'p_year', history: true)]
    public string $profitYearFilter = '';

    #[Url(as: 'p_month', history: true)]
    public string $profitMonthFilter = '';

    public function updatingProfitYearFilter(): void
    {
        // A month only makes sense within a chosen year — clear it if the
        // year changes so the two never disagree.
        $this->profitMonthFilter = '';
    }

    public function with(): array
    {
        $unbilled = JobEntry::whereNull('invoice_id')->with(['company', 'serviceCategory'])->get();

        // Invoices are now generated per company *and* category, so a
        // company can have several concurrent bills ready to go the same
        // month — group by both instead of company alone.
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

        $billedTotal = (float) JobEntry::whereNotNull('invoice_id')->sum('bill_amount');

        // Balance still owed per invoice — bill total (+VAT), minus any
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
                $amount = (float) $invoice->amount;
                $vatAmount = $invoice->vat_percent ? round($amount * (float) $invoice->vat_percent / 100, 2) : 0;

                return max(0, $amount + $vatAmount - (float) $invoice->advancePaid - (float) $invoice->paidViaPayments);
            });

        $thisMonthTotal = JobEntry::whereBetween('entry_date', [
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        ])->sum('bill_amount');

        // Tiffin is billed as one package per department/day, not per
        // ingredient — so entries for the same company/department today are
        // collapsed into a single row instead of one per item (Banana/Egg/
        // Bread), matching how Tiffin already reads everywhere else.
        $todayEntries = JobEntry::whereDate('entry_date', now()->toDateString())
            ->with(['company', 'serviceCategory', 'tiffinDepartment'])
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (JobEntry $entry) => $entry->tiffin_department_id
                ? "tiffin-{$entry->company_id}-{$entry->tiffin_department_id}"
                : "single-{$entry->id}")
            ->map(function ($group) {
                $first = $group->first();

                return (object) [
                    'company' => $first->company,
                    'serviceCategory' => $first->serviceCategory,
                    'label' => $first->tiffin_department_id ? $first->tiffinDepartment->name : $first->supply_type,
                    'items' => $first->tiffin_department_id ? $group->pluck('supply_type')->unique()->sort()->implode(', ') : null,
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
            'thisMonthTotal' => (float) $thisMonthTotal,
            'readyToInvoice' => $readyToInvoice,
            'todayEntries' => $todayEntries,
            'monthlyTrend' => $this->monthlyTrend(),
            'companyBreakdown' => $this->companyBreakdown(),
            ...$this->profit(),
        ];
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
     * Billed vs unbilled totals per company, all-time, so the client can see
     * at a glance which companies still have unbilled work sitting on them.
     *
     * @return array{rows: Collection<int, array{company: Company, billed: float, unbilled: float, total: float, billedPct: float, unbilledPct: float}>, truncatedCount: int}
     */
    private function companyBreakdown(): array
    {
        $eligible = Company::query()
            ->withSum(['jobEntries as billedTotal' => fn ($query) => $query->whereNotNull('invoice_id')], 'bill_amount')
            ->withSum(['jobEntries as unbilledTotal' => fn ($query) => $query->whereNull('invoice_id')], 'bill_amount')
            ->get()
            ->map(fn (Company $company) => [
                'company' => $company,
                'billed' => (float) $company->billedTotal,
                'unbilled' => (float) $company->unbilledTotal,
                'total' => (float) $company->billedTotal + (float) $company->unbilledTotal,
            ])
            ->filter(fn ($row) => $row['total'] > 0)
            ->sortByDesc('total')
            ->values();

        $max = max(1.0, (float) $eligible->max('total'));

        $rows = $eligible->take(self::BREAKDOWN_LIMIT)->map(fn ($row) => [
            ...$row,
            'billedPct' => $this->barPercent($row['billed'], $max),
            'unbilledPct' => $this->barPercent($row['unbilled'], $max),
        ])->values();

        return [
            'rows' => $rows,
            'truncatedCount' => max(0, $eligible->count() - self::BREAKDOWN_LIMIT),
        ];
    }

    /**
     * Profit summary for the client's chosen Company/Year/Month scope —
     * defaults to every entry ever recorded so "entire profit" is the
     * first thing shown, narrowed only once a filter is picked. Broken
     * down by company when every company is in view, or by category once
     * a single company is selected (a company breakdown would just be one
     * row at that point).
     *
     * Profit is only ever realized once the client has actually paid — an
     * entry that's unbilled, or billed but still Due/Partially Paid, has
     * no money in hand yet, so it contributes to Total Billed/Total Cost
     * (work done and cost incurred) but never to Total Profit or Margin
     * until its invoice reaches Paid.
     *
     * @return array{profitCompanies: Collection<int, Company>, profitSelectedCompany: ?Company, profitAvailableYears: Collection<int, int>, profitMonthOptions: Collection<int, string>, profitTotalBilled: float, profitTotalCost: float, profitTotal: float, profitMargin: float, profitBreakdown: array{rows: Collection<int, array<string, mixed>>, truncatedCount: int, byCompany: bool}}
     */
    private function profit(): array
    {
        $companies = Company::orderBy('name')->get();
        $selectedCompany = $this->profitCompanyFilter
            ? $companies->firstWhere('id', (int) $this->profitCompanyFilter)
            : null;

        $companyScopedEntries = JobEntry::with(['company', 'serviceCategory', 'invoice'])
            ->when($this->profitCompanyFilter, fn ($query) => $query->where('company_id', $this->profitCompanyFilter))
            ->get();

        // Offered years always reflect the whole history for this company
        // scope, not just what the current year/month filter leaves behind
        // — same convention as Bill Statement/Daily Summary's dropdowns.
        $availableYears = $companyScopedEntries->map(fn (JobEntry $entry) => $entry->entry_date->year)->unique()->sortDesc()->values();

        $entries = $companyScopedEntries
            ->when($this->profitYearFilter, fn ($rows) => $rows->filter(fn (JobEntry $entry) => $entry->entry_date->year == $this->profitYearFilter))
            ->when($this->profitMonthFilter, fn ($rows) => $rows->filter(fn (JobEntry $entry) => $entry->entry_date->month == $this->profitMonthFilter))
            ->values();

        $paidEntries = $entries->filter(fn (JobEntry $entry) => $entry->invoice?->status === 'paid');

        $totalBilled = (float) $entries->sum('bill_amount');
        $totalCost = (float) $entries->sum('cost_amount');
        $totalPaidProfit = (float) $paidEntries->sum('profit_amount');
        $totalPaidBilled = (float) $paidEntries->sum('bill_amount');

        $groupBy = $selectedCompany ? 'service_category_id' : 'company_id';

        $breakdownEligible = $paidEntries->groupBy($groupBy)
            ->map(fn ($group) => [
                'label' => $selectedCompany ? $group->first()->serviceCategory->name : $group->first()->company->name,
                'company' => $selectedCompany ? null : $group->first()->company,
                'billed' => (float) $group->sum('bill_amount'),
                'cost' => (float) $group->sum('cost_amount'),
                'profit' => (float) $group->sum('profit_amount'),
            ])
            ->sortByDesc('profit')
            ->values();

        $maxProfit = max(1.0, (float) $breakdownEligible->max(fn ($row) => max(0, $row['profit'])));

        $breakdownRows = $breakdownEligible->take(self::BREAKDOWN_LIMIT)->map(fn ($row) => [
            ...$row,
            'margin' => $row['billed'] > 0 ? round($row['profit'] / $row['billed'] * 100, 1) : 0.0,
            'profitPct' => $this->barPercent($row['profit'], $maxProfit),
        ])->values();

        return [
            'profitCompanies' => $companies,
            'profitSelectedCompany' => $selectedCompany,
            'profitAvailableYears' => $availableYears,
            'profitMonthOptions' => collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(2000, $m, 1)->format('F')]),
            'profitTotalBilled' => $totalBilled,
            'profitTotalCost' => $totalCost,
            'profitTotal' => $totalPaidProfit,
            'profitMargin' => $totalPaidBilled > 0 ? round($totalPaidProfit / $totalPaidBilled * 100, 1) : 0.0,
            'profitBreakdown' => [
                'rows' => $breakdownRows,
                'truncatedCount' => max(0, $breakdownEligible->count() - self::BREAKDOWN_LIMIT),
                'byCompany' => ! $selectedCompany,
            ],
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
        <x-stat-card label="Unbilled" :value="number_format($unbilledTotal, 2)" :hint="$unbilledCount.' '.Str::plural('entry', $unbilledCount).' not yet invoiced'">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="5" y="4" width="14" height="17" rx="2" />
                    <rect x="9" y="2.5" width="6" height="3" rx="1" />
                    <path d="M8.5 10h7M8.5 13.5h7M8.5 17h4" />
                </svg>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card label="Billed" :value="number_format($billedTotal, 2)" hint="Already invoiced, all-time">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M8.5 12.5l2.5 2.5 5-5" />
                </svg>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card label="Outstanding (Due)" :value="number_format($outstandingTotal, 2)" hint="Billed, awaiting payment">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 3h12v18l-2.5-1.5L13 21l-2.5-1.5L8 21l-2-1.5V3z" />
                    <path d="M9 8h6M9 11.5h6M9 15h4" />
                </svg>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card label="This Month" :value="number_format($thisMonthTotal, 2)" hint="All activity — billed + unbilled">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M11 3H5a2 2 0 0 0-2 2v6l10 10 8-8L11 3z" />
                    <circle cx="7.5" cy="7.5" r="1.25" />
                </svg>
            </x-slot:icon>
        </x-stat-card>
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

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300">By Company — Billed vs Unbilled</h3>
                <div class="flex items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-brand-500"></span>Billed</span>
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-amber-400"></span>Unbilled</span>
                </div>
            </div>

            @if ($companyBreakdown['rows']->isEmpty())
                <p class="py-8 text-center text-sm text-slate-400 dark:text-slate-500">No billing activity yet.</p>
            @else
                <div class="space-y-4">
                    @foreach ($companyBreakdown['rows'] as $row)
                        <div>
                            <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                                <a href="{{ route('companies.show', $row['company']) }}" wire:navigate class="truncate font-medium text-slate-700 hover:text-brand-600 dark:text-slate-300 dark:hover:text-brand-400">
                                    {{ $row['company']->name }}
                                </a>
                                <span class="shrink-0 text-slate-400 dark:text-slate-500">{{ number_format($row['total'], 2) }}</span>
                            </div>
                            <div
                                class="flex h-2.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700/40"
                                title="Billed {{ number_format($row['billed'], 2) }} · Unbilled {{ number_format($row['unbilled'], 2) }}"
                            >
                                <div class="h-full shrink-0 bg-brand-500" style="width: {{ $row['billedPct'] }}%"></div>
                                <div class="h-full shrink-0 bg-amber-400" style="width: {{ $row['unbilledPct'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($companyBreakdown['truncatedCount'] > 0)
                    <p class="mt-4 text-center text-xs text-slate-400 dark:text-slate-500">+{{ $companyBreakdown['truncatedCount'] }} more {{ Str::plural('company', $companyBreakdown['truncatedCount']) }} not shown</p>
                @endif
            @endif
        </div>
    </div>

    @can('dashboard.view_profit')
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-800">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300">Profit</h3>

            <div class="flex flex-wrap items-center gap-3">
                <x-select-input wire:model.live="profitCompanyFilter" class="w-full sm:w-56">
                    <option value="">All companies</option>
                    @foreach ($profitCompanies as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </x-select-input>

                <x-select-input wire:model.live="profitYearFilter" class="w-full sm:w-32">
                    <option value="">Every year</option>
                    @foreach ($profitAvailableYears as $year)
                        <option value="{{ $year }}">{{ $year }}</option>
                    @endforeach
                </x-select-input>

                <x-select-input wire:model.live="profitMonthFilter" class="w-full sm:w-40" :disabled="! $profitYearFilter">
                    <option value="">{{ $profitYearFilter ? 'Every month' : 'Pick a year first' }}</option>
                    @foreach ($profitMonthOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select-input>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div>
                <p class="text-xs text-slate-500 dark:text-slate-400" title="Work billed regardless of payment status.">Total Billed</p>
                <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($profitTotalBilled, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 dark:text-slate-400" title="Cost incurred regardless of payment status.">Total Cost</p>
                <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($profitTotalCost, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 dark:text-slate-400" title="Only counts invoices marked Paid — Due and Partially Paid bills aren't profit yet.">Total Profit (Paid only)</p>
                <p class="mt-1 text-lg font-semibold {{ $profitTotal >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">{{ number_format($profitTotal, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500 dark:text-slate-400" title="Only counts invoices marked Paid — Due and Partially Paid bills aren't profit yet.">Margin (Paid only)</p>
                <p class="mt-1 text-lg font-semibold {{ $profitMargin >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">{{ number_format($profitMargin, 1) }}%</p>
            </div>
        </div>

        <div class="mt-5 border-t border-slate-100 pt-4 dark:border-slate-700/50">
            <h4 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                {{ $profitBreakdown['byCompany'] ? 'By Company' : 'By Category' }} — Paid Only
            </h4>

            @if ($profitBreakdown['rows']->isEmpty())
                <p class="py-4 text-center text-sm text-slate-400 dark:text-slate-500">No paid invoices in this scope yet.</p>
            @else
                <div class="space-y-4">
                    @foreach ($profitBreakdown['rows'] as $row)
                        <div>
                            <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                                @if ($profitBreakdown['byCompany'])
                                    <a href="{{ route('companies.show', $row['company']) }}" wire:navigate class="truncate font-medium text-slate-700 hover:text-brand-600 dark:text-slate-300 dark:hover:text-brand-400">
                                        {{ $row['label'] }}
                                    </a>
                                @else
                                    <span class="truncate font-medium text-slate-700 dark:text-slate-300">{{ $row['label'] }}</span>
                                @endif
                                <span class="shrink-0 {{ $row['profit'] >= 0 ? 'text-slate-500 dark:text-slate-400' : 'text-red-500 dark:text-red-400' }}">
                                    {{ number_format($row['profit'], 2) }} ({{ number_format($row['margin'], 1) }}%)
                                </span>
                            </div>
                            <div
                                class="h-2.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700/40"
                                title="Billed {{ number_format($row['billed'], 2) }} · Cost {{ number_format($row['cost'], 2) }} · Profit {{ number_format($row['profit'], 2) }}"
                            >
                                <div class="h-full {{ $row['profit'] >= 0 ? 'bg-emerald-500' : 'bg-red-400' }}" style="width: {{ $row['profitPct'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($profitBreakdown['truncatedCount'] > 0)
                    <p class="mt-4 text-center text-xs text-slate-400 dark:text-slate-500">+{{ $profitBreakdown['truncatedCount'] }} more {{ $profitBreakdown['byCompany'] ? Str::plural('company', $profitBreakdown['truncatedCount']) : Str::plural('category', $profitBreakdown['truncatedCount']) }} not shown</p>
                @endif
            @endif
        </div>
    </div>
    @endcan

    <div>
        <h3 class="mb-2 px-1 text-sm font-semibold text-slate-700 dark:text-slate-300">Ready to Invoice</h3>

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
