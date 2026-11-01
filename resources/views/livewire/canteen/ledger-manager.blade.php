<?php

use App\Models\CanteenEntry;
use App\Models\CanteenEntryItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /** 'summary' fits the screen; 'sheet' shows every item, like the Excel. */
    #[Url(as: 'view', history: true)]
    public string $viewMode = 'summary';

    public ?int $editingId = null;

    public string $entry_date = '';

    /**
     * One row per spreadsheet item, keyed by CanteenEntryItem::ITEMS.
     *
     * @var array<string, array{quantity: mixed, purchase_rate: mixed, profit_rate: mixed, profit_amount: mixed}>
     */
    public array $items = [];

    public ?string $daily_cost = null;

    public ?string $company_staff_due = null;

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

    /**
     * Reads like the title on the canteen's monthly sheet, "Sep-26 canteen 30
     * days". Any other filter just names what's in view.
     */
    private function summaryTitle(Collection $monthOptions): string
    {
        if (ctype_digit($this->yearFilter) && $monthOptions->has((int) $this->monthFilter)) {
            $month = Carbon::create((int) $this->yearFilter, (int) $this->monthFilter, 1);

            return "{$month->format('M-y')} canteen {$month->daysInMonth} days";
        }

        return 'Canteen — '.$this->scopeLabel($monthOptions);
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->entry_date = now()->toDateString();
        $this->items = collect(CanteenEntryItem::latestRates())
            ->map(fn (array $rates) => [...$rates, 'quantity' => null, 'profit_amount' => null])
            ->all();
        $this->daily_cost = null;
        $this->company_staff_due = null;
        $this->remarks = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'canteen-entry-form');
    }

    public function startEdit(int $entryId): void
    {
        $entry = CanteenEntry::with('items')->findOrFail($entryId);
        $this->editingId = $entry->id;
        $this->entry_date = $entry->entry_date->format('Y-m-d');

        $saved = $entry->items->keyBy('item');
        $this->items = collect(CanteenEntryItem::latestRates())
            ->map(fn (array $rates, string $item) => $saved->has($item)
                ? [
                    'quantity' => $this->plainNumber($saved[$item]->quantity),
                    'purchase_rate' => $this->plainNumber($saved[$item]->purchase_rate),
                    'profit_rate' => $this->plainNumber($saved[$item]->profit_rate),
                    'profit_amount' => CanteenEntryItem::hasTypedProfit($item) ? $this->plainNumber($saved[$item]->profit_amount) : null,
                ]
                : [...$rates, 'quantity' => null, 'profit_amount' => null])
            ->all();

        $this->daily_cost = $this->plainNumber($entry->daily_cost);
        $this->company_staff_due = (float) $entry->company_staff_due > 0 ? $this->plainNumber($entry->company_staff_due) : null;
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
            'items' => ['array', function (string $attribute, mixed $value, Closure $fail): void {
                if ($message = $this->itemsProblem()) {
                    $fail($message);
                }
            }],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.purchase_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.profit_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.profit_amount' => ['nullable', 'numeric', 'min:0'],
            'daily_cost' => ['required', 'numeric', 'min:0'],
            'company_staff_due' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'items.*.quantity' => 'quantity',
            'items.*.purchase_rate' => 'buy rate',
            'items.*.profit_rate' => 'profit rate',
            'items.*.profit_amount' => 'rice profit',
        ]);

        $lines = $this->calculatedLines()->filter(fn (array $line) => $line['quantity'] > 0);
        $attributes = [
            'entry_date' => $validated['entry_date'],
            'total_purchases' => $lines->sum('purchase_amount'),
            'total_profit' => $lines->sum('profit_amount'),
            'daily_cost' => $validated['daily_cost'],
            'company_staff_due' => $validated['company_staff_due'] ?: 0,
            'remarks' => $validated['remarks'] ? trim($validated['remarks']) : null,
        ];

        DB::transaction(function () use ($attributes, $lines) {
            $entry = $this->editingId
                ? tap(CanteenEntry::findOrFail($this->editingId))->update($attributes)
                : CanteenEntry::create([...$attributes, 'created_by' => auth()->id()]);

            $entry->items()->delete();

            foreach ($lines as $item => $line) {
                $entry->items()->create([...$line, 'item' => $item]);
            }
        });

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
     * @return Collection<string, array{quantity: float, purchase_rate: ?float, purchase_amount: float, profit_rate: ?float, profit_amount: float}>
     */
    private function calculatedLines(): Collection
    {
        return collect($this->items)
            ->filter(fn (array $input, string $item) => array_key_exists($item, CanteenEntryItem::ITEMS))
            ->map(fn (array $input, string $item) => CanteenEntryItem::calculate($item, $input));
    }

    /**
     * Blank is only a mistake on a row that was sold: an empty rate or rice
     * profit there would quietly record 0 instead of the real figure.
     */
    private function itemsProblem(): ?string
    {
        $isBlank = fn (mixed $value): bool => $value === null || $value === '';
        $hasQuantity = false;

        foreach (CanteenEntryItem::ITEMS as $item => $definition) {
            $input = $this->items[$item] ?? [];

            if ((float) ($input['quantity'] ?? 0) <= 0) {
                continue;
            }

            $hasQuantity = true;

            if (CanteenEntryItem::hasPurchase($item) && $isBlank($input['purchase_rate'] ?? null)) {
                return "Enter the buy rate for {$definition['label']}.";
            }

            if (CanteenEntryItem::hasTypedProfit($item) && $isBlank($input['profit_amount'] ?? null)) {
                return "Enter the profit for {$definition['label']}.";
            }

            if (! CanteenEntryItem::hasTypedProfit($item) && $isBlank($input['profit_rate'] ?? null)) {
                return "Enter the profit rate for {$definition['label']}.";
            }
        }

        return $hasQuantity ? null : 'Enter a quantity for at least one item.';
    }

    private function plainNumber(mixed $value): ?string
    {
        return $value === null ? null : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
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
        $staffDueInView = (float) (clone $query)->sum('company_staff_due');
        $itemTotals = CanteenEntryItem::query()
            ->whereIn('canteen_entry_id', (clone $query)->select('id'))
            ->select('item')
            ->selectRaw('SUM(quantity) as quantity, SUM(purchase_amount) as purchase_amount, SUM(profit_amount) as profit_amount')
            ->groupBy('item')
            ->get()
            ->keyBy('item');

        $formLines = $this->calculatedLines();
        $formProfit = (float) $formLines->sum('profit_amount');
        $formNet = $formProfit - (is_numeric($this->daily_cost) ? (float) $this->daily_cost : 0);

        // A chosen month reads top to bottom like the spreadsheet; anything
        // wider keeps the newest day first so today is never buried.
        $isMonthView = $this->yearFilter !== '' && $this->monthFilter !== '';

        return [
            'entries' => $query->with('items')->orderBy('entry_date', $isMonthView ? 'asc' : 'desc')->simplePaginate(31)
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
            'summaryTitle' => $this->summaryTitle($monthOptions),
            'hasActiveFilters' => $this->hasActiveFilters(),
            'availableYears' => $availableYears,
            'monthOptions' => $monthOptions,
            'purchasesInView' => $purchasesInView,
            'profitInView' => $profitInView,
            'costInView' => $costInView,
            'netProfitInView' => round($profitInView - $costInView, 2),
            'staffDueInView' => $staffDueInView,
            'cashInView' => round($profitInView - $costInView - $staffDueInView, 2),
            'itemDefinitions' => CanteenEntryItem::ITEMS,
            'itemTotals' => $itemTotals,
            'formLines' => $formLines,
            'formPurchases' => (float) $formLines->sum('purchase_amount'),
            'formProfit' => $formProfit,
            'formNet' => round($formNet, 2),
            'formCash' => round($formNet - (is_numeric($this->company_staff_due) ? (float) $this->company_staff_due : 0), 2),
        ];
    }
}; ?>

<div class="space-y-3">
    @php
        $cellNumber = function (mixed $value): string {
            if ($value === null) {
                return '';
            }

            $number = (float) $value;

            return number_format($number, floor($number) === $number ? 0 : 2);
        };
        $cellBorder = 'border-l border-slate-200 dark:border-slate-700';
        $stickyHead = 'bg-slate-50 dark:bg-slate-900';
        $plainNumberInput = '[appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none';
        $rateInput = "{$plainNumberInput} block w-20 border-transparent bg-slate-50 py-1 text-sm text-slate-500 shadow-none focus:bg-white focus:text-slate-900 dark:bg-slate-900/50 dark:text-slate-400 dark:focus:text-white";
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
            @foreach (['summary' => 'Summary', 'sheet' => 'Full Sheet'] as $mode => $label)
                <button
                    type="button"
                    wire:click="$set('viewMode', '{{ $mode }}')"
                    aria-pressed="{{ ($viewMode === 'sheet' ? 'sheet' : 'summary') === $mode ? 'true' : 'false' }}"
                    class="rounded-lg px-4 py-1.5 text-sm font-medium transition {{ ($viewMode === 'sheet' ? 'sheet' : 'summary') === $mode ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @can('canteen.create')
            <x-primary-button type="button" wire:click="startCreate">
                + Add Entry
            </x-primary-button>
        @endcan
    </div>

    <div x-data="{ showFilters: false }" class="flex flex-wrap items-center gap-2">
        <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-800">
            @foreach (['' => 'All time', '1' => 'Daily', '7' => 'Weekly', '30' => 'Monthly'] as $value => $label)
                <button
                    type="button"
                    wire:click="$set('rangeFilter', '{{ $value }}')"
                    class="rounded-md px-3 py-1 text-sm font-medium transition {{ $rangeFilter === (string) $value && ($value !== '' || (! $yearFilter && ! $dateFrom && ! $dateTo)) ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <button type="button" x-on:click="showFilters = ! showFilters" x-bind:aria-expanded="showFilters" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 md:hidden dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
            More filters
        </button>

        <div x-bind:class="showFilters ? 'flex' : 'hidden'" class="w-full flex-wrap items-center gap-2 md:flex md:w-auto">
            <x-select-input wire:model.live="yearFilter" aria-label="Year" class="w-full py-1.5 text-sm md:w-28">
                <option value="">Every year</option>
                @foreach ($availableYears as $year)
                    <option value="{{ $year }}">{{ $year }}</option>
                @endforeach
            </x-select-input>

            <x-select-input wire:model.live="monthFilter" aria-label="Month" class="w-full py-1.5 text-sm md:w-36" :disabled="! $yearFilter">
                <option value="">{{ $yearFilter ? 'Every month' : 'Pick a year first' }}</option>
                @foreach ($monthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select-input>

            <x-text-input wire:model.live="dateFrom" type="date" aria-label="From date" class="w-full py-1.5 text-sm md:w-36" />
            <span class="text-sm text-slate-400">to</span>
            <x-text-input wire:model.live="dateTo" type="date" aria-label="To date" class="w-full py-1.5 text-sm md:w-36" />
            <x-text-input wire:model.live.debounce.400ms="search" aria-label="Search remarks" placeholder="Search remarks" class="w-full py-1.5 text-sm md:w-40" />
        </div>

        @if ($hasActiveFilters)
            <button type="button" wire:click="clearFilters" class="text-sm font-medium text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                Clear filters
            </button>
        @endif
    </div>

    <div id="canteen-totals-strip" class="rounded-xl border border-slate-200 bg-white px-4 py-2 shadow-sm dark:border-slate-800 dark:bg-slate-800">
        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-3 lg:grid-cols-7">
            <p class="col-span-2 text-xs text-slate-500 sm:col-span-3 lg:col-span-1 dark:text-slate-400">Totals — {{ $scopeLabel }}</p>
            @foreach ([
                ['Purchases', $purchasesInView, 'text-slate-900 dark:text-white'],
                ['Profit', $profitInView, 'text-slate-900 dark:text-white'],
                ['Daily Cost', $costInView, 'text-slate-900 dark:text-white'],
                ['Net Profit', $netProfitInView, 'text-brand-700 dark:text-brand-300'],
                ['Company Staff Due', $staffDueInView, 'text-slate-900 dark:text-white'],
                ['Cash (Mr.Russel)', $cashInView, 'text-emerald-600 dark:text-emerald-400'],
            ] as [$label, $value, $color])
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                    <dd class="text-sm font-semibold {{ $color }}">{{ number_format($value, 2) }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    @if ($entries->isEmpty())
        <x-empty-state
            :title="$availableYears->isNotEmpty() ? 'No entries match these filters' : 'No canteen entries recorded yet'"
            :message="$availableYears->isNotEmpty() ? 'Try different filters — or clear them above.' : 'Start by adding today\'s quantities and daily cost.'"
        >
            @can('canteen.create')
                <x-slot:action>
                    <x-primary-button type="button" wire:click="startCreate">
                        + Add Entry
                    </x-primary-button>
                </x-slot:action>
            @endcan
        </x-empty-state>
    @elseif ($viewMode === 'sheet')
        <div class="max-h-[75vh] overflow-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <table id="canteen-table" class="w-full text-xs">
                <thead class="sticky top-0 z-20 {{ $stickyHead }}">
                    <tr class="text-slate-700 dark:text-slate-300">
                        <th rowspan="2" class="sticky left-0 z-30 whitespace-nowrap px-3 py-2 text-left {{ $stickyHead }}">Date</th>
                        @foreach ($itemDefinitions as $item => $definition)
                            <th colspan="3" class="{{ $stickyHead }} {{ $cellBorder }} whitespace-nowrap px-3 py-2 text-center">{{ $definition['label'] }}</th>
                        @endforeach
                        <th rowspan="2" class="{{ $stickyHead }} {{ $cellBorder }} px-3 py-2 text-right">Daily Cost</th>
                        <th rowspan="2" class="{{ $stickyHead }} {{ $cellBorder }} px-3 py-2 text-right">Total Purchases</th>
                        <th rowspan="2" class="{{ $stickyHead }} {{ $cellBorder }} px-3 py-2 text-right">Total Profit</th>
                        <th rowspan="2" class="{{ $stickyHead }} {{ $cellBorder }} px-3 py-2 text-right">Net Profit (Dr.)</th>
                        <th rowspan="2" class="{{ $stickyHead }} {{ $cellBorder }} px-3 py-2 text-right">Cash (Mr.Russel) (Cr.)</th>
                        <th rowspan="2" class="{{ $stickyHead }} {{ $cellBorder }} px-3 py-2 text-right">Company Staff Due (Rice) (Cr.)</th>
                        <th rowspan="2" class="{{ $stickyHead }} {{ $cellBorder }} px-3 py-2 text-left">Remarks</th>
                    </tr>
                    <tr class="text-slate-500 dark:text-slate-400">
                        @foreach ($itemDefinitions as $item => $definition)
                            <th class="{{ $stickyHead }} {{ $cellBorder }} px-2 py-1.5 text-right font-medium">Qty</th>
                            <th class="{{ $stickyHead }} px-2 py-1.5 text-right font-medium">Purchase</th>
                            <th class="{{ $stickyHead }} px-2 py-1.5 text-right font-medium">Profit</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        @php $lines = $entry->items->keyBy('item'); @endphp
                        <tr wire:key="canteen-entry-{{ $entry->id }}" class="border-b border-slate-100 text-slate-700 last:border-0 dark:border-slate-700/50 dark:text-slate-300">
                            <td class="sticky left-0 z-10 whitespace-nowrap bg-white px-3 py-2 dark:bg-slate-800">
                                <span class="font-medium text-slate-900 dark:text-white">{{ $entry->entry_date->format('d-M-y') }}</span>
                                @can('canteen.modify')
                                    <button type="button" wire:click="startEdit({{ $entry->id }})" class="ml-2 font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Edit</button>
                                    <button type="button" wire:click="confirmDelete({{ $entry->id }})" class="ml-1.5 font-medium text-red-600 hover:text-red-700 dark:text-red-400">Delete</button>
                                @endcan
                            </td>
                            @foreach ($itemDefinitions as $item => $definition)
                                <td class="{{ $cellBorder }} px-2 py-2 text-right">{{ $cellNumber($lines->get($item)?->quantity) }}</td>
                                <td class="px-2 py-2 text-right">{{ $definition['purchase_rate'] === null ? '' : $cellNumber($lines->get($item)?->purchase_amount) }}</td>
                                <td class="px-2 py-2 text-right">{{ $cellNumber($lines->get($item)?->profit_amount) }}</td>
                            @endforeach
                            <td class="{{ $cellBorder }} px-3 py-2 text-right">{{ $cellNumber($entry->daily_cost) }}</td>
                            <td class="{{ $cellBorder }} px-3 py-2 text-right">{{ $cellNumber($entry->total_purchases) }}</td>
                            <td class="{{ $cellBorder }} px-3 py-2 text-right">{{ $cellNumber($entry->total_profit) }}</td>
                            <td class="{{ $cellBorder }} px-3 py-2 text-right font-semibold {{ $entry->net_profit < 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">{{ $cellNumber($entry->net_profit) }}</td>
                            <td class="{{ $cellBorder }} px-3 py-2 text-right font-semibold {{ $entry->cash < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">{{ $cellNumber($entry->cash) }}</td>
                            <td class="{{ $cellBorder }} px-3 py-2 text-right">{{ (float) $entry->company_staff_due > 0 ? $cellNumber($entry->company_staff_due) : '' }}</td>
                            <td class="{{ $cellBorder }} min-w-40 px-3 py-2 text-left">{{ $entry->remarks }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="sticky bottom-0 z-20 {{ $stickyHead }}">
                    <tr class="border-t border-slate-200 font-semibold text-slate-900 dark:border-slate-700 dark:text-white">
                        <td class="sticky left-0 z-30 whitespace-nowrap px-3 py-2 {{ $stickyHead }}">{{ $hasActiveFilters ? 'Total' : 'Grand Total' }}</td>
                        @foreach ($itemDefinitions as $item => $definition)
                            <td class="{{ $stickyHead }} {{ $cellBorder }} px-2 py-2 text-right">{{ $cellNumber($itemTotals->get($item)?->quantity ?? 0) }}</td>
                            <td class="{{ $stickyHead }} px-2 py-2 text-right">{{ $definition['purchase_rate'] === null ? '' : $cellNumber($itemTotals->get($item)?->purchase_amount ?? 0) }}</td>
                            <td class="{{ $stickyHead }} px-2 py-2 text-right">{{ $cellNumber($itemTotals->get($item)?->profit_amount ?? 0) }}</td>
                        @endforeach
                        <td class="{{ $stickyHead }} {{ $cellBorder }} px-3 py-2 text-right">{{ $cellNumber($costInView) }}</td>
                        <td class="{{ $stickyHead }} {{ $cellBorder }} px-3 py-2 text-right">{{ $cellNumber($purchasesInView) }}</td>
                        <td class="{{ $stickyHead }} {{ $cellBorder }} px-3 py-2 text-right">{{ $cellNumber($profitInView) }}</td>
                        <td class="{{ $stickyHead }} {{ $cellBorder }} px-3 py-2 text-right">{{ $cellNumber($netProfitInView) }}</td>
                        <td class="{{ $stickyHead }} {{ $cellBorder }} px-3 py-2 text-right">{{ $cellNumber($cashInView) }}</td>
                        <td class="{{ $stickyHead }} {{ $cellBorder }} px-3 py-2 text-right">{{ $cellNumber($staffDueInView) }}</td>
                        <td class="{{ $stickyHead }} {{ $cellBorder }}"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @else
        @php
            $summaryCell = 'border border-orange-200 px-2 py-1.5 sm:px-3 dark:border-orange-900/60';
            $summaryHead = 'bg-orange-300 text-slate-900 dark:bg-orange-900 dark:text-orange-50';
            $summaryNumber = function (mixed $value): string {
                $number = (float) $value;

                return number_format($number, floor($number) === $number ? 0 : 2, '.', '');
            };
        @endphp
        <div class="mx-auto max-h-[75vh] max-w-5xl overflow-auto bg-white shadow-sm dark:bg-slate-800">
            <table id="canteen-table" class="w-full border-collapse text-center text-sm text-slate-800 dark:text-slate-200">
                <thead class="sticky top-0 z-20">
                    <tr>
                        <th colspan="6" class="bg-white px-3 py-1 text-sm font-normal text-slate-900 dark:bg-slate-800 dark:text-white">{{ $summaryTitle }}</th>
                    </tr>
                    <tr class="{{ $summaryHead }}">
                        <th class="{{ $summaryHead }} {{ $summaryCell }}">Date</th>
                        <th class="{{ $summaryHead }} {{ $summaryCell }} hidden sm:table-cell">Total Purchases</th>
                        <th class="{{ $summaryHead }} {{ $summaryCell }} hidden sm:table-cell">Total Profit</th>
                        <th class="{{ $summaryHead }} {{ $summaryCell }} hidden sm:table-cell">Daily Cost</th>
                        <th class="{{ $summaryHead }} {{ $summaryCell }}">Net Profit (Dr)</th>
                        @can('canteen.modify')
                            <th class="{{ $summaryHead }} {{ $summaryCell }}">Actions</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr wire:key="canteen-entry-{{ $entry->id }}">
                            <td class="{{ $summaryCell }} whitespace-nowrap">
                                {{ $entry->entry_date->format('d-M-y') }}
                                @if ($entry->remarks)
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $entry->remarks }}</span>
                                @endif
                            </td>
                            <td class="{{ $summaryCell }} hidden sm:table-cell">{{ $summaryNumber($entry->total_purchases) }}</td>
                            <td class="{{ $summaryCell }} hidden sm:table-cell">{{ $summaryNumber($entry->total_profit) }}</td>
                            <td class="{{ $summaryCell }} hidden sm:table-cell">{{ $summaryNumber($entry->daily_cost) }}</td>
                            <td class="{{ $summaryCell }} {{ $entry->net_profit < 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ $summaryNumber($entry->net_profit) }}</td>
                            @can('canteen.modify')
                                <td class="{{ $summaryCell }} whitespace-nowrap">
                                    <button type="button" wire:click="startEdit({{ $entry->id }})" class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Edit</button>
                                    <button type="button" wire:click="confirmDelete({{ $entry->id }})" class="ml-2 font-medium text-red-600 hover:text-red-700 dark:text-red-400">Delete</button>
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="sticky bottom-0 z-20">
                    <tr class="{{ $summaryHead }} font-semibold">
                        <td class="{{ $summaryHead }} {{ $summaryCell }} whitespace-nowrap">{{ $hasActiveFilters ? 'Total' : 'Grand Total' }}</td>
                        <td class="{{ $summaryHead }} {{ $summaryCell }} hidden sm:table-cell">{{ $summaryNumber($purchasesInView) }}</td>
                        <td class="{{ $summaryHead }} {{ $summaryCell }} hidden sm:table-cell">{{ $summaryNumber($profitInView) }}</td>
                        <td class="{{ $summaryHead }} {{ $summaryCell }} hidden sm:table-cell">{{ $summaryNumber($costInView) }}</td>
                        <td class="{{ $summaryHead }} {{ $summaryCell }}">{{ $summaryNumber($netProfitInView) }}</td>
                        @can('canteen.modify')
                            <td class="{{ $summaryHead }} {{ $summaryCell }}"></td>
                        @endcan
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    {{ $entries->links('pagination::simple-tailwind') }}

    @canany(['canteen.create', 'canteen.modify'])
    <x-modal name="canteen-entry-form" focusable>
        <form wire:submit="save" class="space-y-3 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                    {{ $editingId ? 'Edit Entry' : 'Add Canteen Entry' }}
                </h2>
                <div class="flex items-center gap-2">
                    <x-input-label for="canteen_entry_date" value="Date" />
                    <x-text-input wire:model="entry_date" id="canteen_entry_date" type="date" class="w-44 py-1.5 text-sm" required />
                </div>
            </div>
            <x-input-error :messages="$errors->get('entry_date')" />

            <div>
                <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                                <th class="px-3 py-1.5">Item</th>
                                <th class="px-2 py-1.5">Qty</th>
                                <th class="px-2 py-1.5">Buy Rate</th>
                                <th class="px-2 py-1.5">Profit Rate</th>
                                <th class="px-3 py-1.5 text-right">Purchase</th>
                                <th class="px-3 py-1.5 text-right">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($itemDefinitions as $item => $definition)
                                <tr wire:key="canteen-form-{{ $item }}" class="border-b border-slate-100 last:border-0 dark:border-slate-700/50">
                                    <td class="whitespace-nowrap px-3 py-1 font-medium text-slate-800 dark:text-slate-200">{{ $definition['label'] }}</td>
                                    <td class="px-2 py-1">
                                        <x-text-input wire:model.live.debounce.300ms="items.{{ $item }}.quantity" id="item_{{ $item }}_quantity" aria-label="{{ $definition['label'] }} quantity" type="number" step="0.01" min="0" class="{{ $plainNumberInput }} block w-20 py-1 text-sm" />
                                    </td>
                                    <td class="px-2 py-1">
                                        @if ($definition['purchase_rate'] !== null)
                                            <x-text-input wire:model.live.debounce.300ms="items.{{ $item }}.purchase_rate" id="item_{{ $item }}_purchase_rate" aria-label="{{ $definition['label'] }} buy rate" type="number" step="0.01" min="0" tabindex="-1" class="{{ $rateInput }}" />
                                        @endif
                                    </td>
                                    <td class="px-2 py-1">
                                        @if ($definition['profit_rate'] !== null)
                                            <x-text-input wire:model.live.debounce.300ms="items.{{ $item }}.profit_rate" id="item_{{ $item }}_profit_rate" aria-label="{{ $definition['label'] }} profit rate" type="number" step="0.01" min="0" tabindex="-1" class="{{ $rateInput }}" />
                                        @endif
                                    </td>
                                    <td class="px-3 py-1 text-right text-slate-600 dark:text-slate-400">
                                        {{ $definition['purchase_rate'] !== null ? number_format($formLines[$item]['purchase_amount'] ?? 0, 2) : '' }}
                                    </td>
                                    <td class="px-3 py-1 text-right text-slate-600 dark:text-slate-400">
                                        @if ($definition['profit_rate'] === null)
                                            <x-text-input wire:model.live.debounce.300ms="items.{{ $item }}.profit_amount" id="item_{{ $item }}_profit_amount" aria-label="{{ $definition['label'] }} profit" type="number" step="0.01" min="0" class="{{ $plainNumberInput }} ms-auto block w-24 py-1 text-right text-sm" />
                                        @else
                                            {{ number_format($formLines[$item]['profit_amount'] ?? 0, 2) }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-input-error :messages="collect($errors->getMessages())->filter(fn ($messages, $field) => str_starts_with($field, 'items'))->flatten()->unique()->all()" class="mt-2" />
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">The Tab key moves from quantity to quantity. Rates carry over from the last entry; click one to change it.</p>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                    <x-input-label for="canteen_daily_cost" value="Daily Cost" />
                    <x-text-input wire:model.live.debounce.300ms="daily_cost" id="canteen_daily_cost" type="number" step="0.01" min="0" placeholder="e.g. 200" class="{{ $plainNumberInput }} mt-1 block w-full py-1.5 text-sm" required />
                    <x-input-error :messages="$errors->get('daily_cost')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="canteen_staff_due" value="Company Staff Due (Rice)" />
                    <x-text-input wire:model.live.debounce.300ms="company_staff_due" id="canteen_staff_due" type="number" step="0.01" min="0" placeholder="Optional" class="{{ $plainNumberInput }} mt-1 block w-full py-1.5 text-sm" />
                    <x-input-error :messages="$errors->get('company_staff_due')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="canteen_remarks" value="Remarks" />
                    <x-text-input wire:model="remarks" id="canteen_remarks" placeholder="Optional" class="mt-1 block w-full py-1.5 text-sm" />
                    <x-input-error :messages="$errors->get('remarks')" class="mt-1" />
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 p-3 dark:bg-slate-900/50">
                <dl id="canteen-form-summary" class="flex flex-wrap gap-x-5 gap-y-1 text-sm">
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Purchases</dt>
                        <dd class="font-semibold text-slate-900 dark:text-white">{{ number_format($formPurchases, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Profit</dt>
                        <dd class="font-semibold text-slate-900 dark:text-white">{{ number_format($formProfit, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Net Profit</dt>
                        <dd class="font-semibold text-slate-900 dark:text-white">{{ number_format($formNet, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Cash</dt>
                        <dd class="font-semibold text-emerald-600 dark:text-emerald-400">{{ number_format($formCash, 2) }}</dd>
                    </div>
                </dl>
                <div class="ms-auto flex gap-2">
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">
                        Cancel
                    </x-secondary-button>
                    <x-primary-button>
                        {{ $editingId ? 'Save Changes' : 'Add Entry' }}
                    </x-primary-button>
                </div>
            </div>
        </form>
    </x-modal>
    @endcanany

    @can('canteen.modify')
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
    @endcan
</div>
