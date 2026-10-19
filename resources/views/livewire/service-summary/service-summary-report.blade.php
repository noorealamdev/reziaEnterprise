<?php

use App\Models\Company;
use App\Models\JobEntry;
use App\Models\ServiceCategory;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'company', history: true)]
    public string $companyFilter = '';

    #[Url(as: 'category', history: true)]
    public string $categoryFilter = '';

    #[Url(as: 'from', history: true)]
    public string $fromDate = '';

    #[Url(as: 'to', history: true)]
    public string $toDate = '';

    /**
     * Captured once in mount() — a manually-built LengthAwarePaginator needs
     * an explicit 'path', and request()->url() would otherwise resolve to
     * Livewire's own update endpoint on any re-render triggered by a filter
     * change, not this page's real URL.
     */
    public string $paginationPath = '';

    public function mount(): void
    {
        if ($this->fromDate === '' && $this->toDate === '') {
            // Month-to-date — the most useful default, and never shows
            // blank rows for days that haven't happened yet.
            [$start, $end] = $this->presetRanges()['this_month'];
            $this->fromDate = $start->toDateString();
            $this->toDate = $end->toDateString();
        }

        $this->paginationPath = request()->url();
    }

    public function updatingCompanyFilter(): void
    {
        $this->resetPage();
    }

    public function updatingCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatingFromDate(): void
    {
        $this->resetPage();
    }

    public function updatingToDate(): void
    {
        $this->resetPage();
    }

    /**
     * Every preset ends at today rather than running into the future, so
     * "This Week"/"This Month" never show a wall of empty, not-yet-happened
     * days — only "Last 7/30 Days" and "Today" need a fixed start.
     *
     * @return array<string, array{0: Carbon, 1: Carbon}>
     */
    private function presetRanges(): array
    {
        $today = now()->startOfDay();

        return [
            'today' => [$today->copy(), $today->copy()],
            'this_week' => [$today->copy()->startOfWeek(), $today->copy()],
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()],
            'last_7_days' => [$today->copy()->subDays(6), $today->copy()],
            'last_30_days' => [$today->copy()->subDays(29), $today->copy()],
        ];
    }

    public function applyPreset(string $preset): void
    {
        $range = $this->presetRanges()[$preset] ?? null;

        if (! $range) {
            return;
        }

        [$start, $end] = $range;
        $this->fromDate = $start->toDateString();
        $this->toDate = $end->toDateString();
        $this->resetPage();
    }

    /**
     * Every #[Url]-bound filter, appended onto the pagination links —
     * without this, clicking "Next" (a plain <a href>, not a wire:click)
     * does a full page reload to a URL holding only the page number,
     * silently resetting every filter back to its default.
     *
     * @return array<string, string>
     */
    private function urlQueryState(): array
    {
        return array_filter([
            'company' => $this->companyFilter,
            'category' => $this->categoryFilter,
            'from' => $this->fromDate,
            'to' => $this->toDate,
        ], fn ($value) => $value !== '');
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function selectedRange(): array
    {
        $start = Carbon::parse($this->fromDate ?: now());
        $end = Carbon::parse($this->toDate ?: now());

        // A To date picked before From would otherwise query an empty,
        // backwards range — swap them instead of silently showing nothing.
        return $end->lt($start) ? [$end, $start] : [$start, $end];
    }

    public function with(): array
    {
        // 31 (not 30) so a full calendar month always fits on one page —
        // a longer custom range simply spans more pages, same as before.
        $perPage = 31;
        $days = new LengthAwarePaginator([], 0, $perPage, null, ['path' => $this->paginationPath]);
        $totals = ['cost' => 0.0, 'bill' => 0.0, 'profit' => 0.0];

        [$start, $end] = $this->selectedRange();
        $rangeLabel = $start->isSameDay($end)
            ? $start->format('l, d F Y')
            : $start->format('d M Y').' – '.$end->format('d M Y');

        // Highlights a preset chip only when the picked range exactly
        // matches it — any hand-edited range correctly highlights none.
        $activePreset = null;
        foreach ($this->presetRanges() as $key => [$presetStart, $presetEnd]) {
            if ($presetStart->toDateString() === $this->fromDate && $presetEnd->toDateString() === $this->toDate) {
                $activePreset = $key;
                break;
            }
        }

        $selectedCategory = $this->categoryFilter
            ? ServiceCategory::find($this->categoryFilter)
            : null;

        if ($this->companyFilter && $selectedCategory) {
            $isTiffin = $selectedCategory->name === 'Tiffin';

            $entries = JobEntry::where('company_id', $this->companyFilter)
                ->where('service_category_id', $selectedCategory->id)
                ->whereDate('entry_date', '>=', $start)
                ->whereDate('entry_date', '<=', $end)
                // Also needed outside Tiffin for Simba Fashion's per-unit
                // Daily Basic Labour entries, which reuse the same field.
                ->with(['tiffinDepartment', 'collectedFromCompany'])
                ->get();

            // Every day keeps its own row here (rather than one blended
            // total for the whole range), so a multi-day range still shows
            // day-by-day detail.
            $allDays = $entries
                ->groupBy(fn (JobEntry $entry) => $entry->entry_date->toDateString())
                ->map(function ($dayEntries, $dateString) use ($isTiffin) {
                    $rows = $isTiffin
                        ? $this->tiffinRowsForDay($dayEntries)
                        : $this->plainRowsForDay($dayEntries);

                    return [
                        'date' => Carbon::parse($dateString),
                        'rows' => $rows,
                        'cost' => (float) $rows->sum('cost'),
                        'bill' => (float) $rows->sum('bill'),
                        'profit' => (float) $rows->sum('profit'),
                    ];
                })
                ->sortBy(fn ($day) => $day['date'])
                ->values();

            // Totals always reflect every day in the range, never just the
            // page currently on screen — a paginated slice must not silently
            // understate the Grand Total.
            $totals = [
                'cost' => (float) $allDays->sum('cost'),
                'bill' => (float) $allDays->sum('bill'),
                'profit' => (float) $allDays->sum('profit'),
            ];

            // $allDays is a computed collection, not a query, so it's
            // paginated by hand — LengthAwarePaginator wraps a page-sized
            // slice while still tracking the total, the same way Daily
            // Summary's day list paginates its own computed collection.
            $days = (new LengthAwarePaginator(
                $allDays->forPage($this->getPage(), $perPage)->values(),
                $allDays->count(),
                $perPage,
                $this->getPage(),
                ['pageName' => 'page', 'path' => $this->paginationPath]
            ))->appends($this->urlQueryState());
        }

        return [
            'companies' => Company::orderBy('name')->get(),
            'categories' => ServiceCategory::orderBy('sort_order')->get(),
            'selectedCategory' => $selectedCategory,
            'days' => $days,
            'rangeLabel' => $rangeLabel,
            'activePreset' => $activePreset,
            'totals' => $totals,
        ];
    }

    /**
     * Tiffin is billed as one package per department per day, not per
     * ingredient (Banana/Egg/Bread) — so each day's entries for a
     * department collapse into one batch (same rule as the Daily Summary
     * detail page).
     *
     * @param  Collection<int, JobEntry>  $dayEntries
     * @return Collection<int, array{label: string, detail: ?string, quantity: float, costRate: float, billRate: float, cost: float, bill: float, profit: float}>
     */
    private function tiffinRowsForDay(Collection $dayEntries): Collection
    {
        return $dayEntries
            ->groupBy('tiffin_department_id')
            ->map(function ($deptEntries) {
                $cost = (float) $deptEntries->sum('cost_amount');
                $bill = (float) $deptEntries->sum('bill_amount');
                $billingEntry = $deptEntries->first(fn ($entry) => (float) $entry->bill_amount > 0);
                $billRate = (float) ($billingEntry->bill_rate ?? 0);
                $headcount = $billRate > 0 ? round($bill / $billRate, 2) : (float) ($billingEntry->quantity ?? 0);

                return [
                    'label' => $deptEntries->first()->tiffinDepartment->name,
                    'detail' => $deptEntries->pluck('supply_type')->unique()->sort()->implode(', '),
                    'quantity' => $headcount,
                    'costRate' => $headcount > 0 ? $cost / $headcount : 0.0,
                    'billRate' => $billRate,
                    'cost' => $cost,
                    'bill' => $bill,
                    'profit' => (float) $deptEntries->sum('profit_amount'),
                ];
            })
            ->sortBy('label')
            ->values();
    }

    /**
     * Every other category has no batching — each job entry is its own
     * row, the same convention as the Daily Summary detail page's
     * non-Tiffin branch.
     *
     * @param  Collection<int, JobEntry>  $dayEntries
     * @return Collection<int, array{label: string, detail: ?string, quantity: float, costRate: float, billRate: float, cost: float, bill: float, profit: float, shipmentTiffinCost: float}>
     */
    private function plainRowsForDay(Collection $dayEntries): Collection
    {
        return $dayEntries->map(function (JobEntry $entry) {
            $detail = collect([
                // Simba Fashion's per-unit Daily Basic Labour entries reuse
                // tiffin_department_id for the unit — shown here since this
                // report mixes every unit together under one company.
                $entry->tiffin_department_id ? $entry->tiffinDepartment->name : null,
                $entry->buyer,
                $entry->style ? "Style {$entry->style}" : null,
                $entry->floor,
                // Combustible Material only — which company it was
                // collected from.
                $entry->collected_from_company_id ? "Collected from {$entry->collectedFromCompany->name}" : null,
                $entry->challan_no ? "Challan {$entry->challan_no}" : null,
                $entry->remarks,
            ])->filter()->implode(' — ');

            return [
                'label' => $entry->supply_type,
                'detail' => $detail !== '' ? $detail : null,
                'quantity' => (float) $entry->quantity,
                'costRate' => (float) $entry->cost_rate,
                'billRate' => (float) $entry->bill_rate,
                // Loading Unloading only — Shipment Tiffin Cost is a real
                // cost (feeding labourers on the shipment) that's never
                // billed to the factory. It's folded in here so Cost +
                // Profit reconciles to Bill at a glance — profit_amount
                // already has it subtracted underneath, so this isn't
                // double-counted, just surfaced in Cost too.
                'cost' => (float) $entry->cost_amount + (float) $entry->shipment_tiffin_cost,
                'bill' => (float) $entry->bill_amount,
                'profit' => (float) $entry->profit_amount,
                'shipmentTiffinCost' => (float) $entry->shipment_tiffin_cost,
            ];
        })->values();
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Service Summary</h2>

        <div class="flex flex-wrap items-center gap-3">
            <x-select-input wire:model.live="companyFilter" class="w-full sm:w-56">
                <option value="">Select a company…</option>
                @foreach ($companies as $company)
                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                @endforeach
            </x-select-input>

            <x-select-input wire:model.live="categoryFilter" class="w-full sm:w-56">
                <option value="">Select a category…</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </x-select-input>
        </div>
    </div>

    @if ($companyFilter && $selectedCategory)
        <div class="space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <div class="flex flex-wrap gap-2">
                @foreach (['today' => 'Today', 'this_week' => 'This Week', 'this_month' => 'This Month', 'last_7_days' => 'Last 7 Days', 'last_30_days' => 'Last 30 Days'] as $value => $label)
                    <button
                        type="button"
                        wire:click="applyPreset('{{ $value }}')"
                        class="rounded-full border px-3 py-1 text-xs font-medium {{ $activePreset === $value ? 'border-brand-600 bg-brand-50 text-brand-700 dark:border-brand-400 dark:bg-brand-900/30 dark:text-brand-300' : 'border-slate-300 text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $rangeLabel }}</span>

                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-2">
                        <label for="from_date" class="text-xs font-medium text-slate-500 dark:text-slate-400">From</label>
                        <x-text-input wire:model.live="fromDate" id="from_date" type="date" class="w-full sm:w-40" />
                    </div>
                    <div class="flex items-center gap-2">
                        <label for="to_date" class="text-xs font-medium text-slate-500 dark:text-slate-400">To</label>
                        <x-text-input wire:model.live="toDate" id="to_date" type="date" class="w-full sm:w-40" />
                    </div>
                </div>
            </div>
        </div>

        @if ($days->isEmpty())
            <x-empty-state title="Nothing recorded for this range" :message="'No '.$selectedCategory->name.' entries were found for this company in this date range.'" />
        @else
            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <table class="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                            <th class="px-4 py-2">Item</th>
                            <th class="px-4 py-2 text-right">Quantity</th>
                            <th class="px-4 py-2 text-right">Cost Rate</th>
                            <th class="px-4 py-2 text-right">Bill Rate</th>
                            <th class="px-4 py-2 text-right">Cost</th>
                            <th class="px-4 py-2 text-right">Bill</th>
                            <th class="px-4 py-2 text-right">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($days as $day)
                            <tr class="bg-brand-50 dark:bg-brand-900/20">
                                <td colspan="7" class="px-4 py-2 text-sm font-semibold text-slate-900 dark:text-white">
                                    {{ $day['date']->format('l, d F Y') }}
                                </td>
                            </tr>

                            @foreach ($day['rows'] as $row)
                                <tr class="border-b border-slate-100 dark:border-slate-700/50">
                                    <td class="px-4 py-2 text-slate-700 dark:text-slate-300">
                                        {{ $row['label'] }}
                                        @if ($row['detail'])
                                            <span class="text-xs text-slate-400 dark:text-slate-500">— {{ $row['detail'] }}</span>
                                        @endif
                                        @if (($row['shipmentTiffinCost'] ?? 0) > 0)
                                            <span class="block text-xs text-amber-600 dark:text-amber-400">Shipment Tiffin Cost: {{ number_format($row['shipmentTiffinCost'], 2) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right text-slate-600 dark:text-slate-400">{{ number_format($row['quantity'], 2) }}</td>
                                    <td class="px-4 py-2 text-right text-slate-600 dark:text-slate-400">{{ number_format($row['costRate'], 2) }}</td>
                                    <td class="px-4 py-2 text-right text-slate-600 dark:text-slate-400">{{ number_format($row['billRate'], 2) }}</td>
                                    <td class="px-4 py-2 text-right text-slate-600 dark:text-slate-400">{{ number_format($row['cost'], 2) }}</td>
                                    <td class="px-4 py-2 text-right font-medium text-slate-800 dark:text-slate-200">{{ number_format($row['bill'], 2) }}</td>
                                    <td class="px-4 py-2 text-right text-slate-600 dark:text-slate-400">{{ number_format($row['profit'], 2) }}</td>
                                </tr>
                            @endforeach

                            <tr class="border-b-2 border-slate-200 dark:border-slate-700">
                                <td colspan="4" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    Subtotal
                                </td>
                                <td class="px-4 py-2 text-right text-xs font-semibold text-slate-700 dark:text-slate-300">{{ number_format($day['cost'], 2) }}</td>
                                <td class="px-4 py-2 text-right text-xs font-semibold text-slate-700 dark:text-slate-300">{{ number_format($day['bill'], 2) }}</td>
                                <td class="px-4 py-2 text-right text-xs font-semibold text-slate-700 dark:text-slate-300">{{ number_format($day['profit'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-slate-100 dark:bg-slate-900">
                            <td colspan="4" class="px-4 py-3 text-sm font-bold text-slate-900 dark:text-white">Grand Total</td>
                            <td class="px-4 py-3 text-right text-sm font-bold text-slate-900 dark:text-white">{{ number_format($totals['cost'], 2) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-bold text-slate-900 dark:text-white">{{ number_format($totals['bill'], 2) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-bold text-slate-900 dark:text-white">{{ number_format($totals['profit'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{ $days->links('pagination::simple-tailwind') }}
        @endif
    @else
        <x-empty-state title="Select a company and category" message="Choose a company and a service category above to see its activity." />
    @endif
</div>
