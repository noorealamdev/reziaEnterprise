<?php

use App\Models\Company;
use App\Models\JobEntry;
use App\Models\ServiceCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

    #[Url(as: 'year', history: true)]
    public string $yearFilter = '';

    #[Url(as: 'month', history: true)]
    public string $monthFilter = '';

    #[Url(as: 'item', history: true)]
    public string $itemFilter = '';

    #[Url(as: 'status', history: true)]
    public string $statusFilter = '';

    public ?int $confirmingDeleteId = null;

    /**
     * Captured once in mount() — a paginator built or re-resolved mid-session
     * would otherwise take its path from request()->url(), which resolves to
     * Livewire's own update endpoint during an AJAX re-render, not this
     * page's real URL.
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

    public function updatingCategoryFilter(): void
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

    public function updatingItemFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function confirmDelete(int $jobEntryId): void
    {
        $this->confirmingDeleteId = $jobEntryId;
        $this->dispatch('open-modal', 'confirm-job-entry-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('job_entries.modify');

        if ($this->confirmingDeleteId) {
            JobEntry::whereKey($this->confirmingDeleteId)
                ->whereNull('invoice_id')
                ->delete();
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-job-entry-deletion');
    }

    public function with(): array
    {
        // Fetched as plain dates rather than a raw SQL YEAR() aggregate so
        // this stays portable between MySQL (prod) and SQLite (tests).
        $availableYears = JobEntry::query()->pluck('entry_date')
            ->map(fn ($date) => $date->year)
            ->unique()
            ->sortDesc()
            ->values();

        $monthOptions = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(2000, $m, 1)->format('F')]);

        $baseQuery = JobEntry::query()
            ->when($this->companyFilter, fn ($query) => $query->where('company_id', $this->companyFilter))
            ->when($this->categoryFilter, fn ($query) => $query->where('service_category_id', $this->categoryFilter))
            ->when($this->yearFilter, fn ($query) => $query->whereYear('entry_date', $this->yearFilter))
            ->when($this->monthFilter, fn ($query) => $query->whereMonth('entry_date', $this->monthFilter))
            ->when($this->itemFilter, fn ($query) => $query->where('supply_type', $this->itemFilter))
            ->when($this->statusFilter === 'billed', fn ($query) => $query->whereNotNull('invoice_id'))
            ->when($this->statusFilter === 'unbilled', fn ($query) => $query->whereNull('invoice_id'));

        // Paginate by distinct date, not by raw row — a day's entries
        // (e.g. Loading Unloading's whole batch, or Tiffin across every
        // department) must always land together on one page, however many
        // rows that day actually has, never split across two pages.
        $jobEntries = (clone $baseQuery)
            ->select('entry_date')
            ->distinct()
            ->orderByDesc('entry_date')
            ->simplePaginate(10)
            ->setPath($this->paginationPath)
            ->appends(array_filter([
                'company' => $this->companyFilter,
                'category' => $this->categoryFilter,
                'year' => $this->yearFilter,
                'month' => $this->monthFilter,
                'item' => $this->itemFilter,
                'status' => $this->statusFilter,
            ]));

        $pageDates = collect($jobEntries->items())->map(fn (JobEntry $row) => $row->entry_date->toDateString());

        $entriesForGrouping = (clone $baseQuery)
            ->with(['company', 'serviceCategory', 'tiffinDepartment'])
            // entry_date is stored with a time component (Laravel's `date`
            // cast writes the full datetime format), so a raw string
            // whereIn against $pageDates (plain Y-m-d strings) would never
            // match — DATE() normalizes both sides.
            ->whereIn(DB::raw('DATE(entry_date)'), $pageDates)
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get();

        // All Tiffin entries for the same company/day belong under one
        // Tiffin card, regardless of department (Swing/Wash Worker are
        // sub-sections within it) — the factory sees one Tiffin line, not
        // one per department. Loading Unloading's batch items (Big/Small/
        // Wash/Machine Set/Daily Labour/Bosa Gari, saved together from one
        // batch submission) collapse the same way, into one card per
        // company/day — otherwise their shared remarks repeat on every
        // single item row, and the day reads as several unrelated entries
        // instead of one delivery. Every other category renders one row
        // per entry, unchanged.
        $groupedEntries = $entriesForGrouping
            ->groupBy(fn (JobEntry $entry) => $entry->entry_date->toDateString())
            ->map(fn ($entriesForDate) => $entriesForDate
                ->groupBy(function (JobEntry $entry) {
                    if ($entry->tiffin_department_id) {
                        return "tiffin-{$entry->company_id}";
                    }

                    if ($entry->serviceCategory->name === 'Loading Unloading') {
                        return "loading-unloading-{$entry->company_id}";
                    }

                    return "single-{$entry->id}";
                })
                ->values());

        return [
            'jobEntries' => $jobEntries,
            'groupedEntries' => $groupedEntries,
            'companies' => Company::orderBy('name')->get(),
            'serviceCategories' => ServiceCategory::orderBy('sort_order')->get(),
            'itemOptions' => JobEntry::query()->distinct()->orderBy('supply_type')->pluck('supply_type'),
            'availableYears' => $availableYears,
            'monthOptions' => $monthOptions,
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row">
        <x-select-input wire:model.live="companyFilter" class="w-full sm:w-56">
            <option value="">All companies</option>
            @foreach ($companies as $company)
                <option value="{{ $company->id }}">{{ $company->name }}</option>
            @endforeach
        </x-select-input>

        <x-select-input wire:model.live="categoryFilter" class="w-full sm:w-56">
            <option value="">All categories</option>
            @foreach ($serviceCategories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </x-select-input>

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

        <x-select-input wire:model.live="itemFilter" class="w-full sm:w-56">
            <option value="">All items</option>
            @foreach ($itemOptions as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </x-select-input>

        <x-select-input wire:model.live="statusFilter" class="w-full sm:w-40">
            <option value="">All statuses</option>
            <option value="billed">Billed</option>
            <option value="unbilled">Unbilled</option>
        </x-select-input>
    </div>

    @forelse ($groupedEntries as $date => $rowGroups)
        <div>
            <h3 class="mb-2 px-1 text-sm font-semibold text-slate-700 dark:text-slate-300">
                {{ \Illuminate\Support\Carbon::parse($date)->format('l, d M Y') }}
            </h3>

            <div class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white dark:divide-slate-700/50 dark:border-slate-800 dark:bg-slate-800">
                @foreach ($rowGroups as $group)
                    @php $first = $group->first(); @endphp

                    @if ($first->tiffin_department_id)
                        {{-- Tiffin: one card per company/day covering every department, one sub-section per department --}}
                        <div class="p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <x-badge color="brand">{{ $first->serviceCategory->name }}</x-badge>
                                <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ number_format((float) $group->sum('bill_amount'), 2) }}</span>
                            </div>

                            @unless ($companyFilter)
                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ $first->company->name }}</p>
                            @endunless

                            <div class="mt-3 space-y-3">
                                @foreach ($group->groupBy('tiffin_department_id') as $departmentItems)
                                    @php $deptFirst = $departmentItems->first(); @endphp
                                    <div>
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $deptFirst->tiffinDepartment->name }}</span>
                                            @can('job_entries.modify')
                                                @unless ($departmentItems->contains(fn ($e) => $e->isBilled))
                                                    <a
                                                        href="{{ route('job-entries.batch-edit', ['company' => $deptFirst->company_id, 'tiffinDepartment' => $deptFirst->tiffin_department_id, 'date' => $deptFirst->entry_date->toDateString()]) }}"
                                                        wire:navigate
                                                        class="text-xs font-medium text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200"
                                                    >
                                                        Edit
                                                    </a>
                                                @endunless
                                            @endcan
                                        </div>

                                        @if ($deptFirst->challan_no)
                                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Challan {{ $deptFirst->challan_no }}</p>
                                        @endif
                                        @if ($deptFirst->remarks)
                                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $deptFirst->remarks }}</p>
                                        @endif

                                        <div class="mt-1 space-y-1.5">
                                            @foreach ($departmentItems as $item)
                                                <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                                    <span class="text-slate-600 dark:text-slate-400">
                                                        <span class="font-medium text-slate-700 dark:text-slate-300">{{ $item->supply_type }}</span>
                                                        @if ((float) $item->bill_rate > 0)
                                                            <x-badge color="brand">Factory rate {{ number_format((float) $item->bill_rate, 2) }}/person</x-badge>
                                                        @endif
                                                        · Qty {{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}
                                                        · Cost {{ number_format((float) $item->cost_amount, 2) }} · Profit {{ number_format((float) $item->profit_amount, 2) }}
                                                    </span>
                                                    <span class="flex items-center gap-2 font-medium text-slate-700 dark:text-slate-300">
                                                        {{ number_format((float) $item->bill_amount, 2) }}
                                                        @unless ($item->isBilled)
                                                            @can('job_entries.modify')
                                                                <button type="button" wire:click="confirmDelete({{ $item->id }})" class="font-normal text-red-400 hover:text-red-600 dark:text-red-400/80 dark:hover:text-red-300">Delete</button>
                                                            @endcan
                                                        @endunless
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @elseif ($first->serviceCategory->name === 'Loading Unloading' && $group->count() > 1)
                        {{-- Loading Unloading's batch items (Big/Small/Wash/Machine
                        Set/Daily Labour/Bosa Gari) are saved together from one batch
                        submission sharing the same remarks — one card per
                        company/day, one row per item, same idea as Tiffin's card
                        above but flat (no department sub-level). A lone item (e.g.
                        one left over from before the batch flow existed) still falls
                        through to the plain single-entry branch below. --}}
                        <div class="p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <x-badge color="brand">{{ $first->serviceCategory->name }}</x-badge>
                                <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ number_format((float) $group->sum('bill_amount'), 2) }}</span>
                            </div>

                            @unless ($companyFilter)
                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ $first->company->name }}</p>
                            @endunless

                            @if ($first->challan_no)
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Challan {{ $first->challan_no }}</p>
                            @endif
                            @if ($first->remarks)
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $first->remarks }}</p>
                            @endif

                            <div class="mt-3 space-y-1.5">
                                @foreach ($group as $item)
                                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                        <span class="text-slate-600 dark:text-slate-400">
                                            <span class="font-medium text-slate-700 dark:text-slate-300">{{ $item->supply_type }}</span>
                                            · Qty {{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}{{ $item->unit_label ? ' '.$item->unit_label : '' }}
                                            · Cost {{ number_format((float) $item->cost_amount, 2) }} · Profit {{ number_format((float) $item->profit_amount, 2) }}
                                        </span>
                                        <span class="flex items-center gap-2 font-medium text-slate-700 dark:text-slate-300">
                                            {{ number_format((float) $item->bill_amount, 2) }}
                                            @unless ($item->isBilled)
                                                @can('job_entries.modify')
                                                    <a href="{{ route('job-entries.edit', $item) }}" wire:navigate class="font-normal text-slate-400 hover:text-slate-600 dark:text-slate-400/80 dark:hover:text-slate-300">Edit</a>
                                                    <button type="button" wire:click="confirmDelete({{ $item->id }})" class="font-normal text-red-400 hover:text-red-600 dark:text-red-400/80 dark:hover:text-red-300">Delete</button>
                                                @endcan
                                            @endunless
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="flex flex-wrap items-center justify-between gap-3 p-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-badge color="brand">{{ $first->serviceCategory->name }}</x-badge>
                                    <span class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $first->supply_type }}</span>
                                    @if ($first->is_off_day)
                                        <x-badge color="amber">Off Day</x-badge>
                                    @endif
                                </div>

                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">
                                    @unless ($companyFilter)
                                        {{ $first->company->name }} ·
                                    @endunless
                                    @if ($first->buyer)
                                        {{ $first->buyer }}@if ($first->style) (Style {{ $first->style }})@endif ·
                                    @endif
                                    @if ($first->floor)
                                        {{ $first->floor }} ·
                                    @endif
                                    @if ($first->challan_no)
                                        Challan {{ $first->challan_no }} ·
                                    @endif
                                    @if ($first->quantity !== null)
                                        Qty {{ rtrim(rtrim(number_format((float) $first->quantity, 2), '0'), '.') }}{{ $first->unit_label ? ' '.$first->unit_label : '' }} ·
                                    @endif
                                    Rate {{ number_format((float) $first->bill_rate, 2) }} ·
                                    Cost {{ number_format((float) $first->cost_amount, 2) }} · Profit {{ number_format((float) $first->profit_amount, 2) }}
                                </p>
                                @if ($first->remarks)
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $first->remarks }}</p>
                                @endif
                            </div>

                            <div class="flex shrink-0 items-center gap-4">
                                <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ number_format((float) $first->bill_amount, 2) }}</span>

                                @unless ($first->isBilled)
                                    @can('job_entries.modify')
                                        <div class="flex items-center gap-3 text-xs">
                                            <a href="{{ route('job-entries.edit', $first) }}" wire:navigate class="font-medium text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                                                Edit
                                            </a>
                                            <button type="button" wire:click="confirmDelete({{ $first->id }})" class="font-medium text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                                Delete
                                            </button>
                                        </div>
                                    @endcan
                                @endunless
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @empty
        <x-empty-state
            title="No job entries yet"
            message="Add your first entry to get started."
        >
            @can('job_entries.create')
                <x-slot:action>
                    <x-primary-button :href="route('job-entries.create')" wire:navigate>
                        New Entry
                    </x-primary-button>
                </x-slot:action>
            @endcan
        </x-empty-state>
    @endforelse

    {{ $jobEntries->links('pagination::simple-tailwind') }}

    <x-modal name="confirm-job-entry-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                Delete this job entry?
            </h2>

            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                This cannot be undone.
            </p>

            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-danger-button type="button" wire:click="delete">
                    Delete
                </x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
