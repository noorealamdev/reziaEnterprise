<?php

use App\Models\EggSaleTransaction;
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

    /** '' (all), 'received', 'sold' or 'payment'. */
    #[Url(as: 'type', history: true)]
    public string $typeFilter = '';

    /** Matches the description or remarks. */
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

    public string $type = EggSaleTransaction::TYPE_SOLD;

    public string $transaction_date = '';

    public ?string $quantity = null;

    public ?string $rate = null;

    public ?string $amount = null;

    public string $description = '';

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

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['typeFilter', 'search', 'rangeFilter', 'dateFrom', 'dateTo', 'yearFilter', 'monthFilter']);
        $this->resetPage();
    }

    /**
     * Every filter combined (AND) — the ledger list and its period totals
     * both read from this, so they can never disagree about what's in view.
     *
     * @return Builder<EggSaleTransaction>
     */
    private function filteredQuery(): Builder
    {
        $from = $this->validDate($this->dateFrom);
        $to = $this->validDate($this->dateTo);
        $term = trim($this->search);

        return EggSaleTransaction::query()
            ->when(in_array($this->typeFilter, [EggSaleTransaction::TYPE_RECEIVED, EggSaleTransaction::TYPE_SOLD, EggSaleTransaction::TYPE_PAYMENT], true), fn ($q) => $q->where('type', $this->typeFilter))
            ->when($term !== '', fn ($q) => $q->where(fn ($s) => $s
                ->where('description', 'like', "%{$term}%")
                ->orWhere('remarks', 'like', "%{$term}%")))
            ->when(ctype_digit($this->rangeFilter) && (int) $this->rangeFilter > 0, fn ($q) => $q->whereDate('transaction_date', '>=', now()->subDays((int) $this->rangeFilter - 1)->toDateString()))
            ->when($this->yearFilter, fn ($q) => $q->whereYear('transaction_date', $this->yearFilter))
            ->when($this->monthFilter, fn ($q) => $q->whereMonth('transaction_date', $this->monthFilter))
            ->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to));
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
        return $this->typeFilter !== '' || trim($this->search) !== ''
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

    public function startReceived(): void
    {
        $this->startCreate(EggSaleTransaction::TYPE_RECEIVED);
    }

    public function startSold(): void
    {
        $this->startCreate(EggSaleTransaction::TYPE_SOLD);
    }

    public function startPayment(): void
    {
        $this->startCreate(EggSaleTransaction::TYPE_PAYMENT);
    }

    private function startCreate(string $type): void
    {
        $this->editingId = null;
        $this->type = $type;
        $this->transaction_date = now()->toDateString();
        $this->quantity = null;
        $this->rate = null;
        $this->amount = null;
        $this->description = '';
        $this->remarks = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-sale-transaction-form');
    }

    public function startEdit(int $transactionId): void
    {
        $transaction = EggSaleTransaction::findOrFail($transactionId);
        $this->editingId = $transaction->id;
        $this->type = $transaction->type;
        $this->transaction_date = $transaction->transaction_date->format('Y-m-d');
        $this->quantity = (string) $transaction->quantity;
        $this->rate = $transaction->rate !== null ? (string) $transaction->rate : null;
        $this->amount = $transaction->amount !== null ? (string) $transaction->amount : null;
        $this->description = $transaction->description;
        $this->remarks = $transaction->remarks;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-sale-transaction-form');
    }

    public function save(): void
    {
        Gate::authorize($this->editingId ? 'egg_sales.modify' : 'egg_sales.create');

        $isSold = $this->type === EggSaleTransaction::TYPE_SOLD;
        $isPayment = $this->type === EggSaleTransaction::TYPE_PAYMENT;

        $validated = $this->validate([
            'type' => ['required', Rule::in([EggSaleTransaction::TYPE_RECEIVED, EggSaleTransaction::TYPE_SOLD, EggSaleTransaction::TYPE_PAYMENT])],
            'transaction_date' => ['required', 'date'],
            // A payment is pure cash from Akter, no eggs move — everything
            // else is a stock movement and needs a quantity.
            'quantity' => [Rule::requiredIf(! $isPayment), 'nullable', 'numeric', 'min:0.01'],
            // Rate is always required for a sale (what it earned) and
            // optional for a received entry (what it cost to hand to
            // Akter, if known yet) — either way the amount is derived from
            // quantity × rate, never entered directly. A payment has no
            // rate at all; its amount is the only one Akter actually tells
            // us, entered directly since it's a lump sum covering whatever
            // he's settling, not tied to one sale's quantity.
            'rate' => [Rule::requiredIf($isSold), 'nullable', 'numeric', 'min:0.01'],
            'amount' => [Rule::requiredIf($isPayment), 'nullable', 'numeric', 'min:0.01'],
            // Who it was sold to is always required; received/payment notes
            // are optional and default below.
            'description' => [Rule::requiredIf($isSold), 'nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['quantity'] = $isPayment ? 0 : $validated['quantity'];
        $validated['rate'] = $isPayment ? null : $validated['rate'];
        $validated['amount'] = match (true) {
            $isPayment => $validated['amount'],
            $validated['rate'] !== null => round((float) $validated['quantity'] * (float) $validated['rate'], 2),
            default => null,
        };
        $validated['description'] = trim((string) $validated['description']) ?: ($isPayment ? 'Payment' : 'Received');
        $validated['remarks'] = $validated['remarks'] ? trim($validated['remarks']) : null;

        if ($this->editingId) {
            EggSaleTransaction::whereKey($this->editingId)->update($validated);
        } else {
            $validated['created_by'] = auth()->id();
            EggSaleTransaction::create($validated);
        }

        $this->dispatch('close-modal', 'egg-sale-transaction-form');
        $this->notify($this->editingId ? 'Entry updated.' : match (true) {
            $isSold => 'Sale recorded.',
            $isPayment => 'Payment recorded.',
            default => 'Received entry recorded.',
        });
    }

    public function confirmDelete(int $transactionId): void
    {
        $this->confirmingDeleteId = $transactionId;
        $this->dispatch('open-modal', 'confirm-egg-sale-transaction-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('egg_sales.modify');

        if ($this->confirmingDeleteId) {
            EggSaleTransaction::destroy($this->confirmingDeleteId);
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-egg-sale-transaction-deletion');
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
        $availableYears = EggSaleTransaction::pluck('transaction_date')
            ->map(fn ($date) => $date->year)
            ->unique()
            ->sortDesc()
            ->values();

        $monthOptions = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(2000, $m, 1)->format('F')]);

        $query = $this->filteredQuery();

        // Cloned before pagination so the period totals cover every filtered
        // row, not just the current page.
        $receivedInView = (float) (clone $query)->where('type', EggSaleTransaction::TYPE_RECEIVED)->sum('quantity');
        $soldInView = (float) (clone $query)->where('type', EggSaleTransaction::TYPE_SOLD)->sum('quantity');
        $revenueInView = (float) (clone $query)->where('type', EggSaleTransaction::TYPE_SOLD)->sum('amount');
        $paidInView = (float) (clone $query)->where('type', EggSaleTransaction::TYPE_PAYMENT)->sum('amount');

        return [
            'transactions' => $query->orderByDesc('transaction_date')->orderByDesc('id')->simplePaginate(30)
                ->setPath($this->paginationPath)
                ->appends(array_filter([
                    'year' => $this->yearFilter,
                    'month' => $this->monthFilter,
                    'type' => $this->typeFilter,
                    'q' => trim($this->search),
                    'range' => $this->rangeFilter,
                    'from' => $this->dateFrom,
                    'to' => $this->dateTo,
                ])),
            'scopeLabel' => $this->scopeLabel($monthOptions),
            'hasActiveFilters' => $this->hasActiveFilters(),
            'availableYears' => $availableYears,
            'monthOptions' => $monthOptions,
            // Always all-time — how many eggs are on hand right now, how
            // much has ever been earned, how much Akter has paid in, and
            // what he still owes, none of which depend on which month is
            // being looked at.
            'quantityBalance' => EggSaleTransaction::quantityBalance(),
            'totalRevenue' => EggSaleTransaction::totalRevenue(),
            'totalPaidIn' => EggSaleTransaction::totalPaidIn(),
            'outstandingDue' => EggSaleTransaction::outstandingDue(),
            'receivedInView' => $receivedInView,
            'soldInView' => $soldInView,
            'revenueInView' => $revenueInView,
            'paidInView' => $paidInView,
            'amountPreview' => (is_numeric($this->quantity) && is_numeric($this->rate))
                ? round((float) $this->quantity * (float) $this->rate, 2)
                : null,
            ...$this->soldSummary(),
        ];
    }

    /**
     * How much Mr. Akter has sold this week and this month — always these
     * two fixed windows, independent of whatever the filters above are
     * currently narrowed to, so the headline numbers are visible at a
     * glance without having to set up a filter first.
     *
     * @return array{weekSoldQuantity: float, weekSoldRevenue: float, monthSoldQuantity: float, monthSoldRevenue: float}
     */
    private function soldSummary(): array
    {
        $sold = EggSaleTransaction::where('type', EggSaleTransaction::TYPE_SOLD);

        return [
            'weekSoldQuantity' => (float) (clone $sold)->whereBetween('transaction_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])->sum('quantity'),
            'weekSoldRevenue' => (float) (clone $sold)->whereBetween('transaction_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])->sum('amount'),
            'monthSoldQuantity' => (float) (clone $sold)->whereYear('transaction_date', now()->year)->whereMonth('transaction_date', now()->month)->sum('quantity'),
            'monthSoldRevenue' => (float) (clone $sold)->whereYear('transaction_date', now()->year)->whereMonth('transaction_date', now()->month)->sum('amount'),
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Egg Sales</h2>
        <div class="flex flex-wrap items-center gap-2">
            @can('egg_sales.create')
                <x-secondary-button type="button" wire:click="startReceived">
                    + Received
                </x-secondary-button>
                <x-primary-button type="button" wire:click="startSold">
                    + Record Sale
                </x-primary-button>
                <x-secondary-button type="button" wire:click="startPayment">
                    + Record Payment
                </x-secondary-button>
            @endcan
        </div>
    </div>

    <p class="text-xs text-slate-500 dark:text-slate-400">
        Eggs received for external sale, and what's actually sold out of that stock — separate from Tiffin's
        own internal supply. Akter settles in weekly/monthly lump sums rather than per sale, so "Paid in" tracks
        those payments separately from "Sold".
    </p>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Paid in — all time</p>
            <p class="mt-1 text-2xl font-semibold text-emerald-600 dark:text-emerald-400">{{ number_format($totalPaidIn, 2) }}</p>
        </div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-sm dark:border-amber-900 dark:bg-amber-900/20">
            <p class="text-xs text-amber-700 dark:text-amber-300">Outstanding / Due — all time</p>
            <p class="mt-1 text-2xl font-semibold text-amber-800 dark:text-amber-200">{{ number_format($outstandingDue, 2) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Sold this week</p>
            <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ rtrim(rtrim(number_format($weekSoldQuantity, 2), '0'), '.') }} eggs</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ number_format($weekSoldRevenue, 2) }} revenue</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Sold this month</p>
            <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ rtrim(rtrim(number_format($monthSoldQuantity, 2), '0'), '.') }} eggs</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ number_format($monthSoldRevenue, 2) }} revenue</p>
        </div>
    </div>

    <div class="space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
        <div class="flex flex-wrap items-center gap-3">
            <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-800">
                @foreach (['' => 'All time', '1' => 'Today', '7' => '7 Days', '15' => '15 Days', '30' => '30 Days'] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('rangeFilter', '{{ $value }}')"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ $rangeFilter === (string) $value && ($value !== '' || (! $yearFilter && ! $dateFrom && ! $dateTo)) ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <x-text-input wire:model.live.debounce.400ms="search" placeholder="Search description or remarks" class="w-full sm:w-64" />

            @if ($hasActiveFilters)
                <button type="button" wire:click="clearFilters" class="text-sm font-medium text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                    Clear filters
                </button>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-select-input wire:model.live="typeFilter" class="w-full sm:w-48">
                <option value="">Received, sold &amp; paid</option>
                <option value="received">Received only</option>
                <option value="sold">Sold only</option>
                <option value="payment">Payments only</option>
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
            <p class="text-xs text-slate-500 dark:text-slate-400">Received — {{ $scopeLabel }}</p>
            <p class="mt-1 text-lg font-semibold text-emerald-600 dark:text-emerald-400">+{{ rtrim(rtrim(number_format($receivedInView, 2), '0'), '.') }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Sold — {{ $scopeLabel }}</p>
            <p class="mt-1 text-lg font-semibold text-red-600 dark:text-red-400">−{{ rtrim(rtrim(number_format($soldInView, 2), '0'), '.') }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Revenue — {{ $scopeLabel }}</p>
            <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($revenueInView, 2) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Paid — {{ $scopeLabel }}</p>
            <p class="mt-1 text-lg font-semibold text-emerald-600 dark:text-emerald-400">{{ number_format($paidInView, 2) }}</p>
        </div>
    </div>

    @forelse ($transactions as $transaction)
        @php
            $isReceived = $transaction->type === \App\Models\EggSaleTransaction::TYPE_RECEIVED;
            $isPayment = $transaction->type === \App\Models\EggSaleTransaction::TYPE_PAYMENT;
            $badgeColor = match ($transaction->type) {
                \App\Models\EggSaleTransaction::TYPE_RECEIVED => 'green',
                \App\Models\EggSaleTransaction::TYPE_PAYMENT => 'brand',
                default => 'red',
            };
            $badgeLabel = match ($transaction->type) {
                \App\Models\EggSaleTransaction::TYPE_RECEIVED => 'Received',
                \App\Models\EggSaleTransaction::TYPE_PAYMENT => 'Paid',
                default => 'Sold',
            };
        @endphp
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $transaction->description }}</h3>
                        <span class="text-xs text-slate-400 dark:text-slate-500">{{ $transaction->transaction_date->format('d M Y') }}</span>
                        <x-badge :color="$badgeColor">{{ $badgeLabel }}</x-badge>
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        @unless ($isPayment)
                            Qty {{ rtrim(rtrim(number_format((float) $transaction->quantity, 2), '0'), '.') }}
                            @if ($transaction->rate !== null)
                                · Rate {{ number_format((float) $transaction->rate, 2) }}
                            @endif
                            @if ($transaction->amount !== null)
                                ·
                            @endif
                        @endunless
                        @if ($transaction->amount !== null)
                            {{ number_format((float) $transaction->amount, 2) }}
                        @endif
                    </p>
                    @if ($transaction->remarks)
                        <p class="mt-1 whitespace-pre-line text-xs text-slate-500 dark:text-slate-400">{{ $transaction->remarks }}</p>
                    @endif
                </div>
                <span class="shrink-0 text-sm font-semibold {{ $isReceived || $isPayment ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                    @if ($isPayment)
                        {{ number_format((float) $transaction->amount, 2) }}
                    @else
                        {{ $isReceived ? '+' : '−' }}{{ rtrim(rtrim(number_format((float) $transaction->quantity, 2), '0'), '.') }}
                    @endif
                </span>
            </div>

            @can('egg_sales.modify')
                <div class="mt-4 flex items-center gap-3">
                    <x-secondary-button type="button" wire:click="startEdit({{ $transaction->id }})">
                        Edit
                    </x-secondary-button>
                    <x-danger-button type="button" wire:click="confirmDelete({{ $transaction->id }})">
                        Delete
                    </x-danger-button>
                </div>
            @endcan
        </div>
    @empty
        <x-empty-state
            :title="$availableYears->isNotEmpty() ? 'No entries match these filters' : 'No egg sales recorded yet'"
            :message="$availableYears->isNotEmpty() ? 'Try different filters — or clear them above.' : 'Start by logging eggs received for external sale, then record what\'s sold.'"
        >
            @can('egg_sales.create')
                <x-slot:action>
                    <x-primary-button type="button" wire:click="startReceived">
                        + Received
                    </x-primary-button>
                </x-slot:action>
            @endcan
        </x-empty-state>
    @endforelse

    {{ $transactions->links('pagination::simple-tailwind') }}

    <x-modal name="egg-sale-transaction-form" focusable>
        <form wire:submit="save" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                @if ($type === \App\Models\EggSaleTransaction::TYPE_RECEIVED)
                    {{ $editingId ? 'Edit Received Entry' : 'Record Eggs Received' }}
                @elseif ($type === \App\Models\EggSaleTransaction::TYPE_PAYMENT)
                    {{ $editingId ? 'Edit Payment' : 'Record Payment' }}
                @else
                    {{ $editingId ? 'Edit Sale' : 'Record Sale' }}
                @endif
            </h2>

            <div>
                <x-input-label for="egg_transaction_date" value="Date" />
                <x-text-input wire:model="transaction_date" id="egg_transaction_date" type="date" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('transaction_date')" class="mt-2" />
            </div>

            @unless ($type === \App\Models\EggSaleTransaction::TYPE_PAYMENT)
                <div>
                    <x-input-label for="egg_quantity" value="Quantity" />
                    <x-text-input wire:model.live.debounce.400ms="quantity" id="egg_quantity" type="number" step="0.01" min="0.01" placeholder="e.g. 100" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('quantity')" class="mt-2" />
                </div>
            @endunless

            @unless ($type === \App\Models\EggSaleTransaction::TYPE_PAYMENT)
                @php $rateRequired = $type === \App\Models\EggSaleTransaction::TYPE_SOLD; @endphp
                <div>
                    <x-input-label for="egg_rate" :value="$rateRequired ? 'Rate (per egg)' : 'Rate (per egg) — optional'" />
                    <x-text-input wire:model.live.debounce.400ms="rate" id="egg_rate" type="number" step="0.01" min="0.01" placeholder="e.g. 14.00" class="mt-1 block w-full" :required="$rateRequired" />
                    <x-input-error :messages="$errors->get('rate')" class="mt-2" />
                    @if ($amountPreview !== null)
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Amount: {{ number_format($amountPreview, 2) }}</p>
                    @endif
                </div>
            @endunless

            @if ($type === \App\Models\EggSaleTransaction::TYPE_PAYMENT)
                <div>
                    <x-input-label for="egg_amount" value="Amount Paid" />
                    <x-text-input wire:model="amount" id="egg_amount" type="number" step="0.01" min="0.01" placeholder="e.g. 5000.00" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                </div>
            @endif

            <div>
                <x-input-label for="egg_description" :value="$type === \App\Models\EggSaleTransaction::TYPE_SOLD ? 'Sold to' : 'Note'" />
                <x-text-input
                    wire:model="description" id="egg_description"
                    :placeholder="match ($type) {
                        \App\Models\EggSaleTransaction::TYPE_SOLD => 'e.g. Rahim Store',
                        \App\Models\EggSaleTransaction::TYPE_PAYMENT => 'Optional — e.g. Weekly settlement',
                        default => 'Optional — e.g. Allocated for outside sale',
                    }"
                    class="mt-1 block w-full"
                />
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="egg_remarks" value="Remarks" />
                <x-textarea-input wire:model="remarks" id="egg_remarks" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('remarks')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-primary-button>
                    @if ($editingId)
                        Save Changes
                    @else
                        {{ match ($type) {
                            \App\Models\EggSaleTransaction::TYPE_SOLD => 'Record Sale',
                            \App\Models\EggSaleTransaction::TYPE_PAYMENT => 'Record Payment',
                            default => 'Record Received',
                        } }}
                    @endif
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-egg-sale-transaction-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this entry?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                The egg balance will be recalculated without it. This cannot be undone.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="delete">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
