<?php

use App\Models\EggBuyer;
use App\Models\EggCashHandover;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'wallet_range', history: true)]
    public string $rangeFilter = '';

    #[Url(as: 'wallet_year', history: true)]
    public string $yearFilter = '';

    #[Url(as: 'wallet_month', history: true)]
    public string $monthFilter = '';

    #[Url(as: 'wallet_type', history: true)]
    public string $typeFilter = '';

    #[Url(as: 'wallet_from', history: true)]
    public string $dateFrom = '';

    #[Url(as: 'wallet_to', history: true)]
    public string $dateTo = '';

    public ?int $editingHandoverId = null;

    public string $handed_on = '';

    public ?string $amount = null;

    public ?string $note = null;

    public ?int $confirmingDeleteHandoverId = null;

    public function updatingRangeFilter(): void
    {
        $this->yearFilter = '';
        $this->monthFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->resetPage();
    }

    public function updatingYearFilter(): void
    {
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

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingDateFrom(): void
    {
        $this->rangeFilter = '';
        $this->yearFilter = '';
        $this->monthFilter = '';
        $this->resetPage();
    }

    public function updatingDateTo(): void
    {
        $this->updatingDateFrom();
    }

    public function clearFilters(): void
    {
        $this->reset(['rangeFilter', 'yearFilter', 'monthFilter', 'typeFilter', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    public function startCreate(): void
    {
        $this->editingHandoverId = null;
        $this->handed_on = now()->toDateString();
        $this->amount = null;
        $this->note = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-handover-form');
    }

    public function startEdit(int $handoverId): void
    {
        $handover = EggCashHandover::findOrFail($handoverId);

        $this->editingHandoverId = $handover->id;
        $this->handed_on = $handover->handed_on->format('Y-m-d');
        $this->amount = (string) $handover->amount;
        $this->note = $handover->note;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-handover-form');
    }

    /**
     * The limit is checked again inside the transaction with the buyer locked,
     * so two staff saving at once can't hand over the same cash twice.
     */
    public function save(): void
    {
        Gate::authorize($this->editingHandoverId ? 'egg_sales.modify' : 'egg_sales.create');

        $validated = $this->validate([
            'handed_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [], ['handed_on' => 'date']);

        $available = DB::transaction(function () use ($validated) {
            $buyer = EggBuyer::where('uses_cash_wallet', true)->orderBy('id')->lockForUpdate()->firstOrFail();
            $handover = $this->editingHandoverId ? $buyer->cashHandovers()->findOrFail($this->editingHandoverId) : null;
            $available = round($buyer->walletBalance() + (float) ($handover?->amount ?? 0), 2);

            if ((float) $validated['amount'] > $available) {
                return $available;
            }

            $attributes = [
                'handed_on' => $validated['handed_on'],
                'amount' => $validated['amount'],
                'note' => $validated['note'] ? trim($validated['note']) : null,
            ];

            $handover
                ? $handover->update($attributes)
                : $buyer->cashHandovers()->create([...$attributes, 'created_by' => auth()->id()]);

            return null;
        });

        if ($available !== null) {
            $this->addError('amount', 'Only '.number_format($available, 2).' is available in this wallet.');

            return;
        }

        $this->dispatch('close-modal', 'egg-handover-form');
        $this->notify($this->editingHandoverId ? 'Handover updated.' : 'Handover recorded.');
    }

    public function confirmDelete(int $handoverId): void
    {
        $this->confirmingDeleteHandoverId = $handoverId;
        $this->dispatch('open-modal', 'confirm-egg-handover-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('egg_sales.modify');

        EggCashHandover::whereKey($this->confirmingDeleteHandoverId)->delete();

        $this->confirmingDeleteHandoverId = null;
        $this->dispatch('close-modal', 'confirm-egg-handover-deletion');
        $this->notify('Handover deleted. The cash is back in the wallet balance.');
    }

    private function notify(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', message: $message, type: $type);
    }

    /**
     * Sales and handovers in date order, each with the balance after it. On
     * the same day sales come first, since cash can't be handed over before
     * the eggs behind it are sold.
     *
     * @return Collection<int, object>
     */
    private function statement(EggBuyer $buyer): Collection
    {
        $sales = $buyer->sales()->get()->map(fn ($sale) => (object) [
            'key' => "sale-{$sale->id}",
            'type' => 'sale',
            'date' => $sale->sale_date,
            'sortOrder' => 0,
            'recordId' => $sale->id,
            'quantity' => (float) $sale->quantity,
            'rate' => (float) $sale->sale_rate,
            'details' => $sale->remarks,
            'sold' => (float) $sale->sale_amount,
            'handed' => 0.0,
        ]);

        $handovers = $buyer->cashHandovers()->get()->map(fn (EggCashHandover $handover) => (object) [
            'key' => "handover-{$handover->id}",
            'type' => 'handover',
            'date' => $handover->handed_on,
            'sortOrder' => 1,
            'recordId' => $handover->id,
            'details' => $handover->note,
            'sold' => 0.0,
            'handed' => (float) $handover->amount,
        ]);

        $balance = 0.0;

        return $sales->concat($handovers)
            ->sortBy([
                fn (object $first, object $second) => $first->date->toDateString() <=> $second->date->toDateString(),
                ['sortOrder', 'asc'],
                ['recordId', 'asc'],
            ])
            ->values()
            ->map(function (object $entry) use (&$balance) {
                $balance = round($balance + $entry->sold - $entry->handed, 2);
                $entry->balance = $balance;

                return $entry;
            });
    }

    /**
     * @param  Collection<int, object>  $statement
     * @return Collection<int, object>
     */
    private function applyFilters(Collection $statement): Collection
    {
        return $statement
            ->when(ctype_digit($this->rangeFilter) && (int) $this->rangeFilter > 0, fn ($entries) => $entries->filter(fn ($entry) => $entry->date->toDateString() >= now()->subDays((int) $this->rangeFilter - 1)->toDateString()))
            ->when($this->yearFilter, fn ($entries) => $entries->filter(fn ($entry) => (string) $entry->date->year === $this->yearFilter))
            ->when($this->yearFilter && $this->monthFilter, fn ($entries) => $entries->filter(fn ($entry) => (string) $entry->date->month === $this->monthFilter))
            ->when($this->dateFrom, fn ($entries) => $entries->filter(fn ($entry) => $entry->date->toDateString() >= $this->dateFrom))
            ->when($this->dateTo, fn ($entries) => $entries->filter(fn ($entry) => $entry->date->toDateString() <= $this->dateTo))
            ->when(in_array($this->typeFilter, ['sale', 'handover'], true), fn ($entries) => $entries->where('type', $this->typeFilter))
            ->values();
    }

    private function scopeLabel(): string
    {
        return match (true) {
            $this->rangeFilter === '1' => 'today',
            $this->rangeFilter !== '' => "last {$this->rangeFilter} days",
            $this->dateFrom !== '' || $this->dateTo !== '' => 'these dates',
            $this->yearFilter !== '' && $this->monthFilter !== '' => Carbon::create(2000, (int) $this->monthFilter, 1)->format('F').' '.$this->yearFilter,
            $this->yearFilter !== '' => $this->yearFilter,
            default => 'these filters',
        };
    }

    public function with(): array
    {
        $buyer = EggBuyer::cashWalletBuyer();
        $statement = $buyer ? $this->statement($buyer) : collect();
        $filtered = $this->applyFilters($statement);
        $newestFirst = $filtered->reverse()->values();
        $page = $this->getPage();

        return [
            'buyer' => $buyer,
            'entries' => new LengthAwarePaginator($newestFirst->forPage($page, 25)->values(), $newestFirst->count(), 25, $page),
            'totalSold' => round($statement->sum('sold'), 2),
            'totalHanded' => round($statement->sum('handed'), 2),
            'balance' => $buyer?->walletBalance() ?? 0.0,
            'soldInView' => round($filtered->sum('sold'), 2),
            'handedInView' => round($filtered->sum('handed'), 2),
            'quantityInView' => (float) $filtered->sum('quantity'),
            'scopeLabel' => $this->scopeLabel(),
            'hasFilters' => $this->rangeFilter !== '' || $this->yearFilter !== '' || $this->typeFilter !== '' || $this->dateFrom !== '' || $this->dateTo !== '',
            'availableYears' => $statement->map(fn (object $entry) => $entry->date->year)->unique()->sortDesc()->values(),
            'monthOptions' => collect(range(1, 12))->mapWithKeys(fn (int $number) => [$number => Carbon::create(2000, $number, 1)->format('F')]),
        ];
    }
}; ?>

<div class="space-y-4">
    @if ($buyer)
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">Total sold</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format($totalSold, 2) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">Handed to office</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-700 dark:text-emerald-400">{{ number_format($totalHanded, 2) }}</p>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-sm dark:border-amber-900 dark:bg-amber-900/20">
                <p class="text-xs text-amber-800 dark:text-amber-300">Still with {{ $buyer->name }}</p>
                <p class="mt-1 text-2xl font-semibold text-amber-900 dark:text-amber-200">{{ number_format($balance, 2) }}</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex flex-wrap rounded-lg border border-slate-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-800">
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

            @can('egg_sales.create')
                <x-primary-button type="button" wire:click="startCreate">+ Handed to Office</x-primary-button>
            @endcan
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-select-input wire:model.live="typeFilter" aria-label="Entry type" class="w-full sm:w-48">
                <option value="">Sales &amp; handovers</option>
                <option value="sale">Sales only</option>
                <option value="handover">Handovers only</option>
            </x-select-input>

            <x-select-input wire:model.live="yearFilter" aria-label="Year" class="w-full sm:w-32">
                <option value="">Every year</option>
                @foreach ($availableYears as $year)
                    <option value="{{ $year }}">{{ $year }}</option>
                @endforeach
            </x-select-input>

            <x-select-input wire:model.live="monthFilter" aria-label="Month" class="w-full sm:w-40" :disabled="! $yearFilter">
                <option value="">{{ $yearFilter ? 'Every month' : 'Pick a year first' }}</option>
                @foreach ($monthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select-input>

            <span class="text-sm text-slate-500 dark:text-slate-400">Custom dates</span>
            <x-text-input wire:model.live="dateFrom" type="date" aria-label="From date" class="w-full sm:w-44" />
            <span class="text-sm text-slate-400">to</span>
            <x-text-input wire:model.live="dateTo" type="date" aria-label="To date" class="w-full sm:w-44" />

            @if ($hasFilters)
                <button type="button" wire:click="clearFilters" class="text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                    Clear filters
                </button>
            @endif
        </div>

        @if ($hasFilters)
            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">Sold — {{ $scopeLabel }}</p>
                    <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($soldInView, 2) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">Handed to office — {{ $scopeLabel }}</p>
                    <p class="mt-1 text-lg font-semibold text-emerald-700 dark:text-emerald-400">{{ number_format($handedInView, 2) }}</p>
                </div>
            </div>
        @endif

        @if ($entries->isEmpty())
            <x-empty-state
                :title="$hasFilters ? 'No entries match these filters' : 'Nothing in this wallet yet'"
                :message="$hasFilters ? 'Try different filters, or clear them.' : 'Sales recorded for this buyer appear here automatically.'"
            />
        @else
            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <table class="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Details</th>
                            <th class="px-4 py-3 text-right">Sold</th>
                            <th class="px-4 py-3 text-right">To Office</th>
                            <th class="px-4 py-3 text-right">Balance</th>
                            @can('egg_sales.modify')
                                <th class="px-4 py-3 text-right">Actions</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr wire:key="{{ $entry->key }}" class="border-b border-slate-100 last:border-0 dark:border-slate-700/50">
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-400">{{ $entry->date->format('d M Y') }}</td>
                                <td class="px-4 py-3">
                                    <span class="font-medium text-slate-900 dark:text-white">
                                        @if ($entry->type === 'sale')
                                            {{ rtrim(rtrim(number_format($entry->quantity, 2), '0'), '.') }} eggs @ {{ number_format($entry->rate, 2) }}
                                        @else
                                            Handed to Office
                                        @endif
                                    </span>
                                    @if ($entry->details)
                                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $entry->details }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">{{ $entry->sold > 0 ? number_format($entry->sold, 2) : '' }}</td>
                                <td class="px-4 py-3 text-right font-medium text-emerald-700 dark:text-emerald-400">{{ $entry->handed > 0 ? number_format($entry->handed, 2) : '' }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-900 dark:text-white">{{ number_format($entry->balance, 2) }}</td>
                                @can('egg_sales.modify')
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        @if ($entry->type === 'handover')
                                            <button type="button" wire:click="startEdit({{ $entry->recordId }})" class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Edit</button>
                                            <button type="button" wire:click="confirmDelete({{ $entry->recordId }})" class="ml-3 font-medium text-red-600 hover:text-red-700 dark:text-red-400">Delete</button>
                                        @else
                                            <span class="text-xs text-slate-400 dark:text-slate-500">On the Sales tab</span>
                                        @endif
                                    </td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-slate-200 bg-slate-50 font-semibold text-slate-900 dark:border-slate-700 dark:bg-slate-900/50 dark:text-white">
                            <td class="px-4 py-3">{{ $hasFilters ? 'Total for these filters' : 'Grand Total' }}</td>
                            <td class="px-4 py-3">{{ rtrim(rtrim(number_format($quantityInView, 2), '0'), '.') }} eggs</td>
                            <td class="px-4 py-3 text-right">{{ number_format($soldInView, 2) }}</td>
                            <td class="px-4 py-3 text-right text-emerald-700 dark:text-emerald-400">{{ number_format($handedInView, 2) }}</td>
                            <td class="px-4 py-3 text-right">{{ $hasFilters ? '' : number_format($balance, 2) }}</td>
                            @can('egg_sales.modify')
                                <td></td>
                            @endcan
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{ $entries->links() }}
        @endif

        @canany(['egg_sales.create', 'egg_sales.modify'])
            <x-modal name="egg-handover-form" focusable>
                <form wire:submit="save" class="space-y-5 p-6">
                    <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">{{ $editingHandoverId ? 'Edit Handover' : 'Handed to Office' }}</h2>

                    <div class="flex items-center justify-between rounded-lg bg-slate-50 px-4 py-3 dark:bg-slate-900/50">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Still with {{ $buyer->name }}</span>
                        <span class="text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($balance, 2) }}</span>
                    </div>

                    <div>
                        <x-input-label for="handover_date" value="Date" />
                        <x-text-input wire:model="handed_on" id="handover_date" type="date" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('handed_on')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="handover_amount" value="Amount" />
                        <x-text-input wire:model="amount" id="handover_amount" type="number" step="0.01" min="0.01" placeholder="e.g. 1000.00" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="handover_note" value="Note (optional)" />
                        <x-textarea-input wire:model="note" id="handover_note" rows="2" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('note')" class="mt-2" />
                    </div>

                    <div class="flex justify-end gap-3">
                        <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                        <x-primary-button>{{ $editingHandoverId ? 'Save Changes' : 'Record Handover' }}</x-primary-button>
                    </div>
                </form>
            </x-modal>
        @endcanany

        @can('egg_sales.modify')
            <x-modal name="confirm-egg-handover-deletion" focusable>
                <div class="p-6">
                    <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this handover?</h2>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">The amount goes back into the wallet balance. This cannot be undone.</p>
                    <div class="mt-6 flex justify-end gap-3">
                        <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                        <x-danger-button type="button" wire:click="delete">Delete</x-danger-button>
                    </div>
                </div>
            </x-modal>
        @endcan
    @else
        <x-empty-state title="No cash wallet set up" message="No buyer uses a cash wallet." />
    @endif
</div>
