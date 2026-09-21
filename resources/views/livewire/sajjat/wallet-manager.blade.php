<?php

use App\Models\NewspaperPayment;
use App\Models\SajjatTransaction;
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

    #[Url(as: 'wallet', history: true)]
    public string $walletFilter = '';

    /** '' (both), 'top_up' or 'expense'. */
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

    public string $type = SajjatTransaction::TYPE_EXPENSE;

    public string $transaction_date = '';

    public string $wallet = 'cash';

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
        $this->reset(['walletFilter', 'typeFilter', 'search', 'rangeFilter', 'dateFrom', 'dateTo', 'yearFilter', 'monthFilter']);
        $this->resetPage();
    }

    /**
     * Every filter combined (AND) — the ledger list and its period totals
     * both read from this, so they can never disagree about what's in view.
     *
     * @return Builder<SajjatTransaction>
     */
    private function filteredQuery(): Builder
    {
        $from = $this->validDate($this->dateFrom);
        $to = $this->validDate($this->dateTo);
        $term = trim($this->search);

        return SajjatTransaction::query()
            ->when(array_key_exists($this->walletFilter, SajjatTransaction::WALLETS), fn ($q) => $q->where('wallet', $this->walletFilter))
            ->when(in_array($this->typeFilter, [SajjatTransaction::TYPE_TOP_UP, SajjatTransaction::TYPE_EXPENSE], true), fn ($q) => $q->where('type', $this->typeFilter))
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
        return $this->walletFilter !== '' || $this->typeFilter !== '' || trim($this->search) !== ''
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

    public function updatingMonthFilter(): void
    {
        $this->resetPage();
    }

    public function updatingWalletFilter(): void
    {
        $this->resetPage();
    }

    public function startTopUp(): void
    {
        $this->startCreate(SajjatTransaction::TYPE_TOP_UP);
    }

    public function startExpense(): void
    {
        $this->startCreate(SajjatTransaction::TYPE_EXPENSE);
    }

    private function startCreate(string $type): void
    {
        $this->editingId = null;
        $this->type = $type;
        $this->transaction_date = now()->toDateString();
        // Pre-select whichever wallet is already being viewed.
        $this->wallet = array_key_exists($this->walletFilter, SajjatTransaction::WALLETS) ? $this->walletFilter : 'cash';
        $this->amount = null;
        $this->description = '';
        $this->remarks = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'sajjat-form');
    }

    /**
     * An expense created by a newspaper payment is managed from the
     * Newspapers page (deleting the payment refunds the wallet) — changing
     * it here would leave the two out of step.
     */
    private function isNewspaperPayment(int $transactionId): bool
    {
        if (! NewspaperPayment::where('sajjat_transaction_id', $transactionId)->exists()) {
            return false;
        }

        $this->notify('This expense is a newspaper payment — change or delete it from the Newspapers page.', 'error');

        return true;
    }

    public function startEdit(int $transactionId): void
    {
        if ($this->isNewspaperPayment($transactionId)) {
            return;
        }

        $transaction = SajjatTransaction::findOrFail($transactionId);
        $this->editingId = $transaction->id;
        $this->type = $transaction->type;
        $this->transaction_date = $transaction->transaction_date->format('Y-m-d');
        $this->wallet = $transaction->wallet;
        $this->amount = (string) $transaction->amount;
        $this->description = $transaction->description;
        $this->remarks = $transaction->remarks;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'sajjat-form');
    }

    public function save(): void
    {
        Gate::authorize($this->editingId ? 'sajjat.modify' : 'sajjat.create');

        $isExpense = $this->type === SajjatTransaction::TYPE_EXPENSE;

        $validated = $this->validate([
            'type' => ['required', Rule::in([SajjatTransaction::TYPE_TOP_UP, SajjatTransaction::TYPE_EXPENSE])],
            'transaction_date' => ['required', 'date'],
            'wallet' => ['required', Rule::in(array_keys(SajjatTransaction::WALLETS))],
            'amount' => ['required', 'numeric', 'min:0.01'],
            // What was spent on is always required; a top-up's note is optional.
            'description' => [Rule::requiredIf($isExpense), 'nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['description'] = trim((string) $validated['description']) ?: 'Top-up';
        $validated['remarks'] = $validated['remarks'] ? trim($validated['remarks']) : null;

        if ($this->editingId) {
            SajjatTransaction::whereKey($this->editingId)->update($validated);
        } else {
            $validated['created_by'] = auth()->id();
            SajjatTransaction::create($validated);
        }

        $this->dispatch('close-modal', 'sajjat-form');
        $this->notify($this->editingId ? 'Entry updated.' : ($isExpense ? 'Expense recorded.' : 'Top-up recorded.'));
    }

    public function confirmDelete(int $transactionId): void
    {
        if ($this->isNewspaperPayment($transactionId)) {
            return;
        }

        $this->confirmingDeleteId = $transactionId;
        $this->dispatch('open-modal', 'confirm-sajjat-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('sajjat.modify');

        if ($this->confirmingDeleteId && ! $this->isNewspaperPayment($this->confirmingDeleteId)) {
            SajjatTransaction::destroy($this->confirmingDeleteId);
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-sajjat-deletion');
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
        $availableYears = SajjatTransaction::pluck('transaction_date')
            ->map(fn ($date) => $date->year)
            ->unique()
            ->sortDesc()
            ->values();

        $monthOptions = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(2000, $m, 1)->format('F')]);

        $query = $this->filteredQuery();

        // Cloned before pagination so the period totals cover every filtered
        // row, not just the current page.
        $topUpsInView = (float) (clone $query)->where('type', SajjatTransaction::TYPE_TOP_UP)->sum('amount');
        $spentInView = (float) (clone $query)->where('type', SajjatTransaction::TYPE_EXPENSE)->sum('amount');

        // Balances are always all-time — how much Sazzad has on hand right
        // now doesn't depend on which month is being looked at.
        $balances = SajjatTransaction::balances();

        return [
            'transactions' => $query->orderByDesc('transaction_date')->orderByDesc('id')->simplePaginate(30)
                ->setPath($this->paginationPath)
                ->appends(array_filter([
                    'year' => $this->yearFilter,
                    'month' => $this->monthFilter,
                    'wallet' => $this->walletFilter,
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
            'balances' => $balances,
            'totalBalance' => array_sum($balances),
            'topUpsInView' => $topUpsInView,
            'spentInView' => $spentInView,
            'wallets' => SajjatTransaction::WALLETS,
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white">Wallet</span>
            @can('sajjat.newspapers.view')
                <a href="{{ route('sajjat.newspapers') }}" wire:navigate class="rounded-lg px-3 py-1.5 text-sm font-medium text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800">Newspapers</a>
            @endcan
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @can('sajjat.create')
                <x-secondary-button type="button" wire:click="startTopUp">
                    + Top Up
                </x-secondary-button>
                <x-primary-button type="button" wire:click="startExpense">
                    + Record Expense
                </x-primary-button>
            @endcan
        </div>
    </div>

    <p class="text-xs text-slate-500 dark:text-slate-400">
        Money given to Sazzad by bKash or cash, and what he spends of it each day. Each wallet's balance is
        everything topped up into it minus everything spent from it.
    </p>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach ($wallets as $key => $label)
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $label }} balance</p>
                <p class="mt-1 text-2xl font-semibold {{ $balances[$key] < 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">{{ number_format($balances[$key], 2) }}</p>
                @if ($balances[$key] < 0)
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">Overspent — more spent than topped up</p>
                @endif
            </div>
        @endforeach
        <div class="rounded-xl border border-brand-200 bg-brand-50 p-4 shadow-sm dark:border-brand-900 dark:bg-brand-900/20">
            <p class="text-xs text-brand-700 dark:text-brand-300">Total available</p>
            <p class="mt-1 text-2xl font-semibold {{ $totalBalance < 0 ? 'text-red-600 dark:text-red-400' : 'text-brand-800 dark:text-brand-200' }}">{{ number_format($totalBalance, 2) }}</p>
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
            <x-select-input wire:model.live="walletFilter" class="w-full sm:w-40">
                <option value="">Both wallets</option>
                @foreach ($wallets as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </x-select-input>

            <x-select-input wire:model.live="typeFilter" class="w-full sm:w-44">
                <option value="">Top-ups &amp; expenses</option>
                <option value="top_up">Top-ups only</option>
                <option value="expense">Expenses only</option>
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

    <div class="grid grid-cols-2 gap-3">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Topped up — {{ $scopeLabel }}</p>
            <p class="mt-1 text-lg font-semibold text-emerald-600 dark:text-emerald-400">+{{ number_format($topUpsInView, 2) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Spent — {{ $scopeLabel }}</p>
            <p class="mt-1 text-lg font-semibold text-red-600 dark:text-red-400">−{{ number_format($spentInView, 2) }}</p>
        </div>
    </div>

    @forelse ($transactions as $transaction)
        @php $isTopUp = $transaction->type === \App\Models\SajjatTransaction::TYPE_TOP_UP; @endphp
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $transaction->description }}</h3>
                        <span class="text-xs text-slate-400 dark:text-slate-500">{{ $transaction->transaction_date->format('d M Y') }}</span>
                        <x-badge :color="$isTopUp ? 'green' : 'red'">{{ $isTopUp ? 'Top-up' : 'Expense' }}</x-badge>
                        <x-badge :color="$transaction->wallet === 'bkash' ? 'brand' : 'slate'">{{ $wallets[$transaction->wallet] ?? $transaction->wallet }}</x-badge>
                    </div>
                    @if ($transaction->remarks)
                        <p class="mt-1 whitespace-pre-line text-xs text-slate-500 dark:text-slate-400">{{ $transaction->remarks }}</p>
                    @endif
                </div>
                <span class="shrink-0 text-sm font-semibold {{ $isTopUp ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                    {{ $isTopUp ? '+' : '−' }}{{ number_format((float) $transaction->amount, 2) }}
                </span>
            </div>

            @can('sajjat.modify')
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
            :title="$availableYears->isNotEmpty() ? 'No entries match these filters' : 'Nothing recorded for Sazzad yet'"
            :message="$availableYears->isNotEmpty() ? 'Try different filters — or clear them above.' : 'Start by topping up his bKash or cash, then record what he spends.'"
        >
            @can('sajjat.create')
                <x-slot:action>
                    <x-primary-button type="button" wire:click="startTopUp">
                        + Top Up
                    </x-primary-button>
                </x-slot:action>
            @endcan
        </x-empty-state>
    @endforelse

    {{ $transactions->links('pagination::simple-tailwind') }}

    <x-modal name="sajjat-form" focusable>
        <form wire:submit="save" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                @if ($type === \App\Models\SajjatTransaction::TYPE_TOP_UP)
                    {{ $editingId ? 'Edit Top-up' : 'Top Up Sazzad' }}
                @else
                    {{ $editingId ? 'Edit Expense' : 'Record Expense' }}
                @endif
            </h2>

            <div>
                <x-input-label for="sajjat_date" value="Date" />
                <x-text-input wire:model="transaction_date" id="sajjat_date" type="date" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('transaction_date')" class="mt-2" />
            </div>

            <div>
                <x-input-label :value="$type === \App\Models\SajjatTransaction::TYPE_TOP_UP ? 'Sent via' : 'Paid from'" />
                <div class="mt-1 flex gap-4">
                    @foreach ($wallets as $key => $label)
                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                            <input type="radio" wire:model="wallet" value="{{ $key }}" class="text-brand-600 focus:ring-brand-500">
                            {{ $label }}
                            <span class="text-xs text-slate-400">(balance {{ number_format($balances[$key], 2) }})</span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('wallet')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="sajjat_amount" value="Amount" />
                <x-text-input wire:model="amount" id="sajjat_amount" type="number" step="0.01" min="0.01" placeholder="e.g. 5000.00" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('amount')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="sajjat_description" :value="$type === \App\Models\SajjatTransaction::TYPE_TOP_UP ? 'Note' : 'What was it spent on'" />
                <x-text-input
                    wire:model="description" id="sajjat_description"
                    :placeholder="$type === \App\Models\SajjatTransaction::TYPE_TOP_UP ? 'Optional — e.g. Weekly top-up' : 'e.g. Transport, Tea, Loading tip'"
                    class="mt-1 block w-full"
                />
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="sajjat_remarks" value="Remarks" />
                <x-textarea-input wire:model="remarks" id="sajjat_remarks" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('remarks')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-primary-button>
                    {{ $editingId ? 'Save Changes' : ($type === \App\Models\SajjatTransaction::TYPE_TOP_UP ? 'Top Up' : 'Record Expense') }}
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-sajjat-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this entry?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                The wallet balance will be recalculated without it. This cannot be undone.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="delete">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
