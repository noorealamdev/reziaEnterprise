<?php

use App\Models\Company;
use App\Models\JobEntry;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'company', history: true)]
    public string $companyFilter = '';

    #[Url(as: 'year', history: true)]
    public string $yearFilter = '';

    #[Url(as: 'month', history: true)]
    public string $monthFilter = '';

    /**
     * Captured once in mount() — a manually-built LengthAwarePaginator needs
     * an explicit 'path', and request()->url() would otherwise resolve to
     * Livewire's own update endpoint on any re-render triggered by a filter
     * change, not this page's real URL.
     */
    public string $paginationPath = '';

    public function mount(): void
    {
        $this->paginationPath = request()->url();
    }

    public function updatingCompanyFilter(): void
    {
        $this->resetPage();
    }

    public function updatingYearFilter(): void
    {
        // A month only makes sense within a chosen year — clear it if the
        // year changes so the two never disagree.
        $this->monthFilter = '';
        $this->resetPage();
    }

    public function updatingMonthFilter(): void
    {
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
            'year' => $this->yearFilter,
            'month' => $this->monthFilter,
        ], fn ($value) => $value !== '');
    }

    public function with(): array
    {
        $days = new LengthAwarePaginator([], 0, 15, null, ['path' => $this->paginationPath]);
        $availableYears = collect();

        if ($this->companyFilter) {
            $allDays = JobEntry::where('company_id', $this->companyFilter)
                ->get(['entry_date', 'bill_amount'])
                ->groupBy(fn (JobEntry $entry) => $entry->entry_date->toDateString())
                ->map(fn ($group) => [
                    'date' => $group->first()->entry_date,
                    'count' => $group->count(),
                    'total' => $group->sum('bill_amount'),
                ])
                ->sortByDesc('date')
                ->values();

            // Offered years always reflect this company's whole history,
            // not just what the current year/month filter leaves behind.
            $availableYears = $allDays->map(fn ($day) => $day['date']->year)->unique()->sortDesc()->values();

            $filteredDays = $allDays
                ->when($this->yearFilter, fn ($days) => $days->filter(fn ($day) => $day['date']->year == $this->yearFilter))
                ->when($this->monthFilter, fn ($days) => $days->filter(fn ($day) => $day['date']->month == $this->monthFilter))
                ->values();

            // $days is a computed collection, not a query, so it's paginated
            // by hand — LengthAwarePaginator wraps a page-sized slice while
            // still tracking the total, the same way Job Entries/Tiffin
            // Purchases paginate their real Eloquent queries.
            $perPage = 15;
            $days = (new LengthAwarePaginator(
                $filteredDays->forPage($this->getPage(), $perPage)->values(),
                $filteredDays->count(),
                $perPage,
                $this->getPage(),
                ['pageName' => 'page', 'path' => $this->paginationPath]
            ))->appends($this->urlQueryState());
        }

        $monthOptions = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(2000, $m, 1)->format('F')]);

        return [
            'companies' => Company::orderBy('name')->get(),
            'days' => $days,
            'availableYears' => $availableYears,
            'monthOptions' => $monthOptions,
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
        <x-select-input wire:model.live="companyFilter" class="w-full sm:w-64">
            <option value="">Select a company…</option>
            @foreach ($companies as $company)
                <option value="{{ $company->id }}">{{ $company->name }}</option>
            @endforeach
        </x-select-input>

        @if ($companyFilter)
            <x-select-input wire:model.live="yearFilter" class="w-full sm:w-32">
                <option value="">Every year</option>
                @foreach ($availableYears as $year)
                    <option value="{{ $year }}">{{ $year }}</option>
                @endforeach
            </x-select-input>

            <x-select-input wire:model.live="monthFilter" class="w-full sm:w-40" :disabled="! $yearFilter">
                <option value="">{{ $yearFilter ? 'Every month' : 'Pick a year first' }}</option>
                @foreach ($monthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select-input>
        @endif
    </div>

    @if (! $companyFilter)
        <x-empty-state title="Select a company" message="Choose a company above to see its daily activity." />
    @elseif ($days->isEmpty())
        <x-empty-state
            :title="$availableYears->isNotEmpty() ? 'No days match these filters' : 'No entries yet'"
            :message="$availableYears->isNotEmpty() ? 'Try a different year or month — or clear the filters above.' : 'This company has no job entries recorded.'"
        />
    @else
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <table class="w-full min-w-[420px] text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                        <th class="px-4 py-2">Date</th>
                        <th class="px-4 py-2 text-right">Entries</th>
                        <th class="px-4 py-2 text-right">Total Bill</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($days as $day)
                        <tr class="border-b border-slate-100 last:border-0 dark:border-slate-700/50">
                            <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-200">{{ $day['date']->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">{{ $day['count'] }}</td>
                            <td class="px-4 py-3 text-right font-medium text-slate-800 dark:text-slate-200">{{ number_format($day['total'], 2) }}</td>
                            <td class="px-4 py-3 text-right">
                                <a
                                    href="{{ route('daily-summary.show', ['company' => $companyFilter, 'date' => $day['date']->toDateString()]) }}"
                                    wire:navigate
                                    class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300"
                                >
                                    View →
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $days->links('pagination::simple-tailwind') }}
    @endif
</div>
