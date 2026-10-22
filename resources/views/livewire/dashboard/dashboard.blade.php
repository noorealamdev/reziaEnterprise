<?php

use App\Models\Company;
use App\Models\Expense;
use App\Models\JobEntry;
use App\Support\BusinessSnapshot;
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

    public function updatingProfitYearFilter(): void
    {
        // A month only makes sense within a chosen year — clear it if the
        // year changes so the two never disagree.
        $this->profitMonthFilter = '';
    }

    public function with(): array
    {
        $unbilled = BusinessSnapshot::unbilled();

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

        $readyToInvoice = BusinessSnapshot::readyToInvoice();
        $invoiceOverview = BusinessSnapshot::invoiceOverview();

        $profitThisMonth = BusinessSnapshot::profitThisMonth();
        $profitLastMonth = BusinessSnapshot::profitLastMonth();

        return [
            'companyCount' => Company::count(),
            'activeCompanyCount' => Company::where('is_active', true)->count(),
            'unbilledTotal' => (float) $unbilled->sum('bill_amount'),
            'unbilledCount' => $unbilled->count(),
            // How much of the unbilled total is left over from a month
            // that's already ended — the same "at risk of actually being
            // missed" flag readyToInvoice() sorts on, surfaced as a count
            // for the at-a-glance strip below.
            'overdueUnbilledCount' => $readyToInvoice->where('isOverdue', true)->sum('count'),
            'billedTotal' => BusinessSnapshot::billedTotal(),
            'outstandingTotal' => BusinessSnapshot::outstandingTotal(),
            'unpaidInvoiceCount' => $invoiceOverview->firstWhere('statusValue', 'unpaid')['count'],
            'profitThisMonth' => $profitThisMonth,
            'profitLastMonth' => $profitLastMonth,
            // Null rather than 0% when last month had no paid profit at all
            // to compare against — "up infinity percent" is meaningless, so
            // the trend badge hides itself instead of showing a nonsense
            // number.
            'profitTrendPct' => $profitLastMonth != 0.0
                ? round(($profitThisMonth - $profitLastMonth) / abs($profitLastMonth) * 100, 1)
                : null,
            'readyToInvoice' => $readyToInvoice,
            'todayEntries' => $todayEntries,
            'monthlyTrend' => $this->monthlyTrend(),
            'dailyExpenseTrend' => $this->dailyExpenseTrend(),
            'invoiceOverview' => $invoiceOverview,
            'agreementDeadlines' => BusinessSnapshot::agreementDeadlines(self::BREAKDOWN_LIMIT),
            ...BusinessSnapshot::companiesFinancials($this->profitCompanyFilter, $this->profitYearFilter, $this->profitMonthFilter),
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
    <div>
        <div class="flex flex-wrap items-baseline justify-between gap-2 px-1">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">This Month at a Glance</h2>
            <span class="text-xs text-slate-400 dark:text-slate-500">{{ now()->format('F Y') }}</span>
        </div>

        <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <a
                @if (Gate::allows('dashboard.view_profit')) href="#companies-cost-profit" wire:navigate @endif
                class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition dark:border-slate-800 dark:bg-slate-800 {{ Gate::allows('dashboard.view_profit') ? 'hover:border-brand-300 hover:shadow-md dark:hover:border-brand-700' : '' }}"
            >
                <p class="text-xs text-slate-500 dark:text-slate-400">Profit so far this month</p>
                <div class="mt-1 flex flex-wrap items-baseline gap-2">
                    <span class="text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format($profitThisMonth, 2) }}</span>
                    @if ($profitTrendPct !== null)
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $profitTrendPct >= 0 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300' }}">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                @if ($profitTrendPct >= 0)
                                    <path d="M5 19L19 5M19 5H9M19 5V15" />
                                @else
                                    <path d="M5 5L19 19M19 19H9M19 19V9" />
                                @endif
                            </svg>
                            {{ number_format(abs($profitTrendPct), 1) }}%
                        </span>
                    @endif
                </div>
                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">vs {{ number_format($profitLastMonth, 2) }} last month &middot; cash actually collected</p>
            </a>

            <a
                @if (Gate::allows('bill_statement.view')) href="{{ route('bill-statement.index', ['status' => 'unpaid']) }}" wire:navigate @endif
                class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition dark:border-slate-800 dark:bg-slate-800 {{ Gate::allows('bill_statement.view') ? 'hover:border-brand-300 hover:shadow-md dark:hover:border-brand-700' : '' }}"
            >
                <p class="text-xs text-slate-500 dark:text-slate-400">Still owed to you</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format($outstandingTotal, 2) }}</p>
                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ $unpaidInvoiceCount }} {{ Str::plural('invoice', $unpaidInvoiceCount) }} billed, awaiting payment</p>
            </a>

            <a
                @if (Gate::allows('bill_statement.view')) href="{{ route('bill-statement.index', ['status' => 'unbilled']) }}" wire:navigate @endif
                class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-sm transition dark:border-amber-900 dark:bg-amber-900/20 {{ Gate::allows('bill_statement.view') ? 'hover:border-amber-300 hover:shadow-md' : '' }}"
            >
                <p class="text-xs text-amber-700 dark:text-amber-300">Work not yet billed</p>
                <p class="mt-1 text-2xl font-semibold text-amber-800 dark:text-amber-200">{{ number_format($unbilledTotal, 2) }}</p>
                <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                    {{ $unbilledCount }} {{ Str::plural('entry', $unbilledCount) }}
                    @if ($overdueUnbilledCount > 0)
                        &middot; {{ $overdueUnbilledCount }} from an earlier month
                    @endif
                </p>
            </a>
        </div>
    </div>

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
