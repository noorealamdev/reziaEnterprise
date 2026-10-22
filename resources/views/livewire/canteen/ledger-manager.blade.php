<?php

use App\Models\CanteenEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'year', history: true)]
    public string $yearFilter = '';

    #[Url(as: 'month', history: true)]
    public string $monthFilter = '';

    /** Matches the remarks. */
    #[Url(as: 'q', history: true)]
    public string $search = '';

    /** Quick range in days: '' (use the other date filters), '1' (today), '7', '15' or '30'. */
    #[Url(as: 'range', history: true)]
    public string $rangeFilter = '';

    #[Url(as: 'from', history: true)]
    public string $dateFrom = '';

    #[Url(as: 'to', history: true)]
    public string $dateTo = '';

    public ?int $editingId = null;

    public string $entry_date = '';

    public ?string $total_purchases = null;

    public ?string $total_profit = null;

    public ?string $daily_cost = null;

    public ?string $remarks = null;

    public ?int $confirmingDeleteId = null;

    /**
     * Captured once in mount() — request()->url() would otherwise resolve to
     * Livewire's own update endpoint during an AJAX re-render, not this
     * page's real URL.
     */
    public string $paginationPath = '';

    public function mount(): void
    {
        $this->paginationPath = request()->url();
    }

    public function updatingYearFilter(): void
    {
        // A month only makes sense within a chosen year — and the date scope
        // is one of quick range / year-month / custom from-to, never a mix
        // of them, so picking one clears the others.
        $this->monthFilter = '';
        $this->rangeFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->resetPage();
    }

    public function updatingMonthFilter(): void
    {
        $this->resetPage();
    }

    public function updatingRangeFilter(): void
    {
        $this->yearFilter = '';
        $this->monthFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->resetPage();
    }

    public function updatingDateFrom(): void
    {
        $this->yearFilter = '';
        $this->monthFilter = '';
        $this->rangeFilter = '';
        $this->resetPage();
    }

    public function updatingDateTo(): void
    {
        $this->yearFilter = '';
        $this->monthFilter = '';
        $this->rangeFilter = '';
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'rangeFilter', 'dateFrom', 'dateTo', 'yearFilter', 'monthFilter']);
        $this->resetPage();
    }

    /**
     * Every filter combined (AND) — the ledger list and its period totals
     * both read from this, so they can never disagree about what's in view.
     *
     * @return Builder<CanteenEntry>
     */
    private function filteredQuery(): Builder
    {
        $from = $this->validDate($this->dateFrom);
        $to = $this->validDate($this->dateTo);
        $term = trim($this->search);

        return CanteenEntry::query()
            ->when($term !== '', fn ($q) => $q->where('remarks', 'like', "%{$term}%"))
            ->when(ctype_digit($this->rangeFilter) && (int) $this->rangeFilter > 0, fn ($q) => $q->whereDate('entry_date', '>=', now()->subDays((int) $this->rangeFilter - 1)->toDateString()))
            ->when($this->yearFilter, fn ($q) => $q->whereYear('entry_date', $this->yearFilter))
            ->when($this->monthFilter, fn ($q) => $q->whereMonth('entry_date', $this->monthFilter))
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to));
    }

    /**
     * The date filters come straight from the URL (?from=…), so anything
     * that isn't a real Y-m-d date is ignored rather than trusted.
     */
    private function validDate(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->toDateString() === $value ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function hasActiveFilters(): bool
    {
        return trim($this->search) !== ''
            || $this->rangeFilter !== '' || $this->dateFrom !== '' || $this->dateTo !== ''
            || $this->yearFilter !== '' || $this->monthFilter !== '';
    }

    private function scopeLabel(Collection $monthOptions): string
    {
        return match (true) {
            $this->rangeFilter === '1' => 'today',
            $this->rangeFilter !== '' => "last {$this->rangeFilter} days",
            $this->validDate($this->dateFrom) || $this->validDate($this->dateTo) => (
                ($this->validDate($this->dateFrom) ? Carbon::parse($this->dateFrom)->format('d M Y') : 'start').' – '
                .($this->validDate($this->dateTo) ? Carbon::parse($this->dateTo)->format('d M Y') : 'today')
            ),
            $this->yearFilter !== '' && $this->monthFilter !== '' => $monthOptions[$this->monthFilter].' '.$this->yearFilter,
            $this->yearFilter !== '' => $this->yearFilter,
            default => 'all-time',
        };
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->entry_date = now()->toDateString();
        $this->total_purchases = null;
        $this->total_profit = null;
        $this->daily_cost = null;
        $this->remarks = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'canteen-entry-form');
    }

    public function startEdit(int $entryId): void
    {
        $entry = CanteenEntry::findOrFail($entryId);
        $this->editingId = $entry->id;
        $this->entry_date = $entry->entry_date->format('Y-m-d');
        $this->total_purchases = (string) $entry->total_purchases;
        $this->total_profit = (string) $entry->total_profit;
        $this->daily_cost = (string) $entry->daily_cost;
        $this->remarks = $entry->remarks;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'canteen-entry-form');
    }

    public function save(): void
    {
        Gate::authorize($this->editingId ? 'canteen.modify' : 'canteen.create');

        $validated = $this->validate([
            'entry_date' => [
                'required',
                'date',
                Rule::unique('canteen_entries', 'entry_date')->ignore($this->editingId),
            ],
            'total_purchases' => ['required', 'numeric', 'min:0'],
            'total_profit' => ['required', 'numeric', 'min:0'],
            'daily_cost' => ['required', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['remarks'] = $validated['remarks'] ? trim($validated['remarks']) : null;

        if ($this->editingId) {
            CanteenEntry::whereKey($this->editingId)->update($validated);
        } else {
            $validated['created_by'] = auth()->id();
            CanteenEntry::create($validated);
        }

        $this->dispatch('close-modal', 'canteen-entry-form');
        $this->notify($this->editingId ? 'Entry updated.' : 'Entry recorded.');
    }

    public function confirmDelete(int $entryId): void
    {
        $this->confirmingDeleteId = $entryId;
        $this->dispatch('open-modal', 'confirm-canteen-entry-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('canteen.modify');

        if ($this->confirmingDeleteId) {
            CanteenEntry::destroy($this->confirmingDeleteId);
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-canteen-entry-deletion');
        $this->notify('Entry deleted.');
    }

    /**
     * Sends the confirmation straight to the browser's toast stack in this
     * same response, rather than through the session flash the layout polls
     * for — a flash can be lost when another request from the same session
     * (the toast poll itself) overlaps the save, so the alert never showed.
     */
    private function notify(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', message: $message, type: $type);
    }

    public function with(): array
    {
        $availableYears = CanteenEntry::pluck('entry_date')
            ->map(fn ($date) => $date->year)
            ->unique()
            ->sortDesc()
            ->values();

        $monthOptions = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(2000, $m, 1)->format('F')]);

        $query = $this->filteredQuery();

        // Cloned before pagination so the period totals cover every filtered
        // row, not just the current page.
        $purchasesInView = (float) (clone $query)->sum('total_purchases');
        $profitInView = (float) (clone $query)->sum('total_profit');
        $costInView = (float) (clone $query)->sum('daily_cost');

        return [
            'entries' => $query->orderByDesc('entry_date')->simplePaginate(30)
                ->setPath($this->paginationPath)
                ->appends(array_filter([
                    'year' => $this->yearFilter,
                    'month' => $this->monthFilter,
                    'q' => trim($this->search),
                    'range' => $this->rangeFilter,
                    'from' => $this->dateFrom,
                    'to' => $this->dateTo,
                ])),
            'scopeLabel' => $this->scopeLabel($monthOptions),
            'hasActiveFilters' => $this->hasActiveFilters(),
            'availableYears' => $availableYears,
            'monthOptions' => $monthOptions,
            'purchasesInView' => $purchasesInView,
            'profitInView' => $profitInView,
            'costInView' => $costInView,
            'netProfitInView' => round($profitInView - $costInView, 2),
            'netProfitPreview' => (is_numeric($this->total_profit) && is_numeric($this->daily_cost))
                ? round((float) $this->total_profit - (float) $this->daily_cost, 2)
                : null,
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Canteen</h2>
        <div class="flex flex-wrap items-center gap-2">
            @can('canteen.create')
                <x-primary-button type="button" wire:click="startCreate">
                    + Add Entry
                </x-primary-button>
            @endcan
        </div>
    </div>

    <p class="text-xs text-slate-500 dark:text-slate-400">
        One entry per day — purchases, profit and daily cost. Net Profit is purchases' profit minus the day's cost,
        computed automatically.
    </p>

    <div class="space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
        <div class="flex flex-wrap items-center gap-3">
            <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-800">
                @foreach (['' => 'All time', '1' => 'Daily', '7' => 'Weekly', '30' => 'Monthly'] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('rangeFilter', '{{ $value }}')"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ $rangeFilter === (string) $value && ($value !== '' || (! $yearFilter && ! $dateFrom && ! $dateTo)) ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <x-text-input wire:model.live.debounce.400ms="search" placeholder="Search remarks" class="w-full sm:w-64" />

            @if ($hasActiveFilters)
                <button type="button" wire:click="clearFilters" class="text-sm font-medium text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                    Clear filters
                </button>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-3">
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
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <span class="text-sm text-slate-500 dark:text-slate-400">Custom dates</span>
            <x-text-input wire:model.live="dateFrom" type="date" aria-label="From date" class="w-full sm:w-44" />
            <span class="text-sm text-slate-400">to</span>
            <x-text-input wire:model.live="dateTo" type="date" aria-label="To date" class="w-full sm:w-44" />
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Purchases — {{ $scopeLabel }}</p>
            <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($purchasesInView, 2) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Profit — {{ $scopeLabel }}</p>
            <p class="mt-1 text-lg font-semibold text-emerald-600 dark:text-emerald-400">{{ number_format($profitInView, 2) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Daily Cost — {{ $scopeLabel }}</p>
            <p class="mt-1 text-lg font-semibold text-red-600 dark:text-red-400">{{ number_format($costInView, 2) }}</p>
        </div>
        <div class="rounded-xl border border-brand-200 bg-brand-50 p-4 shadow-sm dark:border-brand-900 dark:bg-brand-900/20">
            <p class="text-xs text-brand-700 dark:text-brand-300">Net Profit — {{ $scopeLabel }}</p>
            <p class="mt-1 text-lg font-semibold text-brand-800 dark:text-brand-200">{{ number_format($netProfitInView, 2) }}</p>
        </div>
    </div>

    @forelse ($entries as $entry)
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $entry->entry_date->format('d M Y') }}</h3>
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Purchases {{ number_format((float) $entry->total_purchases, 2) }}
                        · Profit {{ number_format((float) $entry->total_profit, 2) }}
                        · Daily Cost {{ number_format((float) $entry->daily_cost, 2) }}
                    </p>
                    @if ($entry->remarks)
                        <p class="mt-1 whitespace-pre-line text-xs text-slate-500 dark:text-slate-400">{{ $entry->remarks }}</p>
                    @endif
                </div>
                <span class="shrink-0 text-sm font-semibold {{ $entry->net_profit < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                    {{ number_format($entry->net_profit, 2) }}
                </span>
            </div>

            @can('canteen.modify')
                <div class="mt-4 flex items-center gap-3">
                    <x-secondary-button type="button" wire:click="startEdit({{ $entry->id }})">
                        Edit
                    </x-secondary-button>
                    <x-danger-button type="button" wire:click="confirmDelete({{ $entry->id }})">
                        Delete
                    </x-danger-button>
                </div>
            @endcan
        </div>
    @empty
        <x-empty-state
            :title="$availableYears->isNotEmpty() ? 'No entries match these filters' : 'No canteen entries recorded yet'"
            :message="$availableYears->isNotEmpty() ? 'Try different filters — or clear them above.' : 'Start by adding today\'s purchases, profit and cost.'"
        >
            @can('canteen.create')
                <x-slot:action>
                    <x-primary-button type="button" wire:click="startCreate">
                        + Add Entry
                    </x-primary-button>
                </x-slot:action>
            @endcan
        </x-empty-state>
    @endforelse

    {{ $entries->links('pagination::simple-tailwind') }}

    <x-modal name="canteen-entry-form" focusable>
        <form wire:submit="save" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingId ? 'Edit Entry' : 'Add Canteen Entry' }}
            </h2>

            <div>
                <x-input-label for="canteen_entry_date" value="Date" />
                <x-text-input wire:model="entry_date" id="canteen_entry_date" type="date" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('entry_date')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="canteen_total_purchases" value="Total Purchases" />
                <x-text-input wire:model="total_purchases" id="canteen_total_purchases" type="number" step="0.01" min="0" placeholder="e.g. 15115.00" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('total_purchases')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="canteen_total_profit" value="Total Profit" />
                <x-text-input wire:model.live.debounce.400ms="total_profit" id="canteen_total_profit" type="number" step="0.01" min="0" placeholder="e.g. 4750.00" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('total_profit')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="canteen_daily_cost" value="Daily Cost" />
                <x-text-input wire:model.live.debounce.400ms="daily_cost" id="canteen_daily_cost" type="number" step="0.01" min="0" placeholder="e.g. 200.00" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('daily_cost')" class="mt-2" />
                @if ($netProfitPreview !== null)
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Net Profit: {{ number_format($netProfitPreview, 2) }}</p>
                @endif
            </div>

            <div>
                <x-input-label for="canteen_remarks" value="Remarks" />
                <x-textarea-input wire:model="remarks" id="canteen_remarks" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('remarks')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-primary-button>
                    {{ $editingId ? 'Save Changes' : 'Add Entry' }}
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-canteen-entry-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this entry?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                This cannot be undone.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="delete">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
