<?php

use App\Models\EggBuyer;
use App\Models\EggInvoice;
use App\Models\EggInvoicePayment;
use App\Models\EggSale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'range', history: true)]
    public string $rangeFilter = '';

    #[Url(as: 'year', history: true)]
    public string $yearFilter = '';

    #[Url(as: 'month', history: true)]
    public string $monthFilter = '';

    #[Url(as: 'buyer', history: true)]
    public string $buyerFilter = '';

    #[Url(as: 'billing', history: true)]
    public string $billingFilter = '';

    public ?int $editingSaleId = null;

    public string $sale_date = '';

    public string $buyer_name = '';

    public ?string $sale_quantity = null;

    public ?string $sale_rate = null;

    public ?string $sale_remarks = null;

    public bool $showNewBuyerFields = false;

    public ?string $new_buyer_name = null;

    public ?string $new_buyer_phone = null;

    public ?string $new_buyer_address = null;

    public ?int $confirmingDeleteSaleId = null;

    public function updatingRangeFilter(): void
    {
        $this->yearFilter = '';
        $this->monthFilter = '';
        $this->resetPage();
    }

    public function updatingYearFilter(): void
    {
        $this->monthFilter = '';
        $this->rangeFilter = '';
        $this->resetPage();
    }

    public function updatingMonthFilter(): void
    {
        $this->resetPage();
    }

    public function updatingBuyerFilter(): void
    {
        $this->resetPage();
    }

    public function updatingBillingFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['rangeFilter', 'yearFilter', 'monthFilter', 'buyerFilter', 'billingFilter']);
        $this->resetPage();
    }

    /**
     * Picking a buyer suggests the rate from their most recent sale, but never
     * replaces a rate the staff member has already typed.
     */
    public function updatedBuyerName(): void
    {
        $buyer = EggBuyer::where('name', trim($this->buyer_name))->first();

        if (! $buyer) {
            return;
        }

        $this->resetErrorBag('buyer_name');

        if ($this->sale_rate !== null && $this->sale_rate !== '') {
            return;
        }

        $lastRate = $buyer->sales()->latest('sale_date')->latest('id')->value('sale_rate');

        if ($lastRate !== null) {
            $this->sale_rate = rtrim(rtrim(number_format((float) $lastRate, 2, '.', ''), '0'), '.');
        }
    }

    public function startCreateSale(): void
    {
        $this->editingSaleId = null;
        $this->sale_date = now()->toDateString();
        $this->buyer_name = '';
        $this->sale_quantity = null;
        $this->sale_rate = null;
        $this->sale_remarks = null;
        $this->closeNewBuyerFields();
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-sale-form');
    }

    public function startEditSale(int $saleId): void
    {
        $sale = EggSale::with('buyer')->findOrFail($saleId);

        if ($sale->egg_invoice_id) {
            $this->notify('This sale is on a bill. Delete the bill first to change it.', 'error');

            return;
        }

        $this->editingSaleId = $sale->id;
        $this->sale_date = $sale->sale_date->format('Y-m-d');
        $this->buyer_name = $sale->buyer->name;
        $this->sale_quantity = rtrim(rtrim((string) $sale->quantity, '0'), '.');
        $this->sale_rate = (string) $sale->sale_rate;
        $this->sale_remarks = $sale->remarks;
        $this->closeNewBuyerFields();
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-sale-form');
    }

    public function openNewBuyerFields(): void
    {
        $this->showNewBuyerFields = true;
        $this->new_buyer_name = trim($this->buyer_name) ?: null;
    }

    public function closeNewBuyerFields(): void
    {
        $this->showNewBuyerFields = false;
        $this->new_buyer_name = null;
        $this->new_buyer_phone = null;
        $this->new_buyer_address = null;
        $this->resetErrorBag(['new_buyer_name', 'new_buyer_phone', 'new_buyer_address']);
    }

    public function addBuyer(): void
    {
        Gate::authorize('egg_sales.create');

        $this->new_buyer_name = trim((string) $this->new_buyer_name);

        $validated = $this->validate([
            'new_buyer_name' => ['required', 'string', 'max:255', Rule::unique('egg_buyers', 'name')],
            'new_buyer_phone' => ['nullable', 'string', 'max:255'],
            'new_buyer_address' => ['nullable', 'string', 'max:255'],
        ], [], ['new_buyer_name' => 'buyer name']);

        $buyer = EggBuyer::create([
            'name' => $validated['new_buyer_name'],
            'phone' => $validated['new_buyer_phone'] ? trim($validated['new_buyer_phone']) : null,
            'address' => $validated['new_buyer_address'] ? trim($validated['new_buyer_address']) : null,
            'created_by' => auth()->id(),
        ]);

        $this->buyer_name = $buyer->name;
        $this->closeNewBuyerFields();
        $this->notify('Buyer added.');
    }

    public function saveSale(): void
    {
        Gate::authorize($this->editingSaleId ? 'egg_sales.modify' : 'egg_sales.create');

        $sale = $this->editingSaleId ? EggSale::with('buyer')->findOrFail($this->editingSaleId) : null;

        if ($sale?->egg_invoice_id) {
            $this->dispatch('close-modal', 'egg-sale-form');
            $this->notify('This sale is on a bill. Delete the bill first to change it.', 'error');

            return;
        }

        $this->buyer_name = trim($this->buyer_name);

        $validated = $this->validate([
            'sale_date' => ['required', 'date'],
            'buyer_name' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($sale): void {
                    $buyer = EggBuyer::where('name', $value)->first();

                    if (! $buyer) {
                        $fail('Choose a buyer from the list, or add a new one.');
                    } elseif (! $buyer->is_active && $buyer->id !== $sale?->egg_buyer_id) {
                        $fail('This buyer is inactive. Make them active on the Buyers tab first.');
                    }
                },
            ],
            'sale_quantity' => ['required', 'numeric', 'min:0.01'],
            'sale_rate' => ['required', 'numeric', 'min:0.01'],
            'sale_remarks' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'buyer_name' => 'buyer',
            'sale_quantity' => 'quantity',
            'sale_rate' => 'rate',
            'sale_remarks' => 'remarks',
        ]);

        $attributes = [
            'sale_date' => $validated['sale_date'],
            'egg_buyer_id' => EggBuyer::where('name', $validated['buyer_name'])->value('id'),
            'quantity' => $validated['sale_quantity'],
            'sale_rate' => $validated['sale_rate'],
            'sale_amount' => round((float) $validated['sale_quantity'] * (float) $validated['sale_rate'], 2),
            'remarks' => $validated['sale_remarks'] ? trim($validated['sale_remarks']) : null,
        ];

        $amountStayingWithBuyer = $sale && $attributes['egg_buyer_id'] === $sale->egg_buyer_id ? $attributes['sale_amount'] : 0.0;

        $isSaved = DB::transaction(function () use ($sale, $attributes, $amountStayingWithBuyer) {
            if ($sale && ! $this->walletCanLose($sale, (float) $sale->sale_amount - $amountStayingWithBuyer)) {
                return false;
            }

            $sale ? $sale->update($attributes) : EggSale::create([...$attributes, 'created_by' => auth()->id()]);

            return true;
        });

        if (! $isSaved) {
            $this->addError($attributes['egg_buyer_id'] === $sale->egg_buyer_id ? 'sale_quantity' : 'buyer_name', $this->walletLimitMessage($sale));

            return;
        }

        $this->dispatch('close-modal', 'egg-sale-form');
        $this->notify($sale ? 'Sale updated.' : 'Sale recorded.');
    }

    public function confirmDeleteSale(int $saleId): void
    {
        $this->confirmingDeleteSaleId = $saleId;
        $this->dispatch('open-modal', 'confirm-egg-sale-deletion');
    }

    public function deleteSale(): void
    {
        Gate::authorize('egg_sales.modify');

        $sale = EggSale::find($this->confirmingDeleteSaleId);
        $this->confirmingDeleteSaleId = null;
        $this->dispatch('close-modal', 'confirm-egg-sale-deletion');

        if ($sale?->egg_invoice_id) {
            $this->notify('This sale is on a bill. Delete the bill first to remove it.', 'error');

            return;
        }

        if (! $sale) {
            return;
        }

        $isDeleted = DB::transaction(function () use ($sale) {
            if (! $this->walletCanLose($sale, (float) $sale->sale_amount)) {
                return false;
            }

            return $sale->delete();
        });

        $isDeleted ? $this->notify('Sale deleted.') : $this->notify($this->walletLimitMessage($sale), 'error');
    }

    /**
     * A wallet buyer's sales are what funds their handovers, so a sale can't
     * shrink past cash that has already reached the office. The buyer stays
     * locked for the rest of the transaction so a handover can't slip in.
     */
    private function walletCanLose(EggSale $sale, float $amount): bool
    {
        return EggBuyer::whereKey($sale->egg_buyer_id)->lockForUpdate()->firstOrFail()->canLoseSalesAmount($amount);
    }

    private function walletLimitMessage(EggSale $sale): string
    {
        return "That cash has already reached the office, so this would take {$sale->buyer->name}'s wallet below 0.00. Correct the handovers on the wallet first.";
    }

    private function notify(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', message: $message, type: $type);
    }

    /**
     * @return Builder<EggSale>
     */
    private function filteredSales(): Builder
    {
        return EggSale::query()
            ->when(ctype_digit($this->rangeFilter) && (int) $this->rangeFilter > 0, fn ($query) => $query->whereDate('sale_date', '>=', now()->subDays((int) $this->rangeFilter - 1)->toDateString()))
            ->when($this->yearFilter, fn ($query) => $query->whereYear('sale_date', $this->yearFilter))
            ->when($this->monthFilter, fn ($query) => $query->whereMonth('sale_date', $this->monthFilter))
            ->when($this->buyerFilter, fn ($query) => $query->where('egg_buyer_id', $this->buyerFilter))
            ->when($this->billingFilter === 'unbilled', fn ($query) => $query->whereNull('egg_invoice_id')->whereRelation('buyer', 'uses_cash_wallet', false))
            ->when($this->billingFilter === 'billed', fn ($query) => $query->whereNotNull('egg_invoice_id'))
            ->when($this->billingFilter === 'wallet', fn ($query) => $query->whereRelation('buyer', 'uses_cash_wallet', true));
    }

    public function with(): array
    {
        $filtered = $this->filteredSales();
        $week = [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()];
        $month = [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];

        return [
            'sales' => (clone $filtered)->with(['buyer', 'invoice'])->orderByDesc('sale_date')->orderByDesc('id')->paginate(25),
            'filteredQuantity' => (float) (clone $filtered)->sum('quantity'),
            'filteredAmount' => (float) (clone $filtered)->sum('sale_amount'),
            'hasFilters' => $this->rangeFilter !== '' || $this->yearFilter !== '' || $this->buyerFilter !== '' || $this->billingFilter !== '',
            'weekQuantity' => (float) EggSale::whereBetween('sale_date', $week)->sum('quantity'),
            'weekAmount' => (float) EggSale::whereBetween('sale_date', $week)->sum('sale_amount'),
            'monthQuantity' => (float) EggSale::whereBetween('sale_date', $month)->sum('quantity'),
            'monthAmount' => (float) EggSale::whereBetween('sale_date', $month)->sum('sale_amount'),
            'unbilledAmount' => (float) EggSale::whereNull('egg_invoice_id')->whereRelation('buyer', 'uses_cash_wallet', false)->sum('sale_amount'),
            'unbilledCount' => EggSale::whereNull('egg_invoice_id')->whereRelation('buyer', 'uses_cash_wallet', false)->count(),
            'owedOnBills' => max(0.0, (float) EggSale::whereNotNull('egg_invoice_id')->sum('sale_amount') - (float) EggInvoicePayment::sum('amount')),
            'unpaidBillCount' => EggInvoice::whereIn('status', ['due', 'partial'])->count(),
            'allBuyers' => EggBuyer::orderBy('name')->get(['id', 'name']),
            'activeBuyers' => EggBuyer::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'availableYears' => EggSale::pluck('sale_date')->map(fn (Carbon $date) => $date->year)->unique()->sortDesc()->values(),
            'monthOptions' => collect(range(1, 12))->mapWithKeys(fn (int $number) => [$number => Carbon::create(2000, $number, 1)->format('F')]),
            'amountPreview' => is_numeric($this->sale_quantity) && is_numeric($this->sale_rate)
                ? round((float) $this->sale_quantity * (float) $this->sale_rate, 2)
                : null,
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Sold this week</p>
            <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($weekAmount, 2) }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ rtrim(rtrim(number_format($weekQuantity, 2), '0'), '.') }} eggs</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Sold this month</p>
            <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($monthAmount, 2) }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ rtrim(rtrim(number_format($monthQuantity, 2), '0'), '.') }} eggs</p>
        </div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-sm dark:border-amber-900 dark:bg-amber-900/20">
            <p class="text-xs text-amber-800 dark:text-amber-300">Not billed yet</p>
            <p class="mt-1 text-lg font-semibold text-amber-900 dark:text-amber-200">{{ number_format($unbilledAmount, 2) }}</p>
            <p class="text-xs text-amber-800 dark:text-amber-300">{{ $unbilledCount }} {{ Str::plural('sale', $unbilledCount) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Still owed on bills</p>
            <p class="mt-1 text-lg font-semibold {{ $owedOnBills > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">{{ number_format($owedOnBills, 2) }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $unpaidBillCount }} unpaid {{ Str::plural('bill', $unpaidBillCount) }}</p>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex flex-wrap rounded-lg border border-slate-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-800">
            @foreach (['' => 'All time', '1' => 'Today', '7' => '7 Days', '30' => '30 Days'] as $value => $label)
                <button
                    type="button"
                    wire:click="$set('rangeFilter', '{{ $value }}')"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ $rangeFilter === (string) $value && ($value !== '' || ! $yearFilter) ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @can('egg_sales.create')
            <x-primary-button type="button" wire:click="startCreateSale">
                + Record Sale
            </x-primary-button>
        @endcan
    </div>

    <div class="flex flex-wrap items-center gap-3">
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

        <x-select-input wire:model.live="buyerFilter" aria-label="Buyer" class="w-full sm:w-48">
            <option value="">Every buyer</option>
            @foreach ($allBuyers as $buyer)
                <option value="{{ $buyer->id }}">{{ $buyer->name }}</option>
            @endforeach
        </x-select-input>

        <x-select-input wire:model.live="billingFilter" aria-label="Billing status" class="w-full sm:w-44">
            <option value="">All sales</option>
            <option value="unbilled">Not billed yet</option>
            <option value="billed">On a bill</option>
            <option value="wallet">Cash wallet</option>
        </x-select-input>

        @if ($hasFilters)
            <button type="button" wire:click="clearFilters" class="text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                Clear filters
            </button>
        @endif
    </div>

    @if ($sales->isEmpty())
        <x-empty-state
            :title="$hasFilters ? 'No sales match these filters' : 'No egg sales recorded yet'"
            :message="$hasFilters ? 'Try different filters, or clear them.' : 'Record your first sale to get started.'"
        >
            @can('egg_sales.create')
                <x-slot:action>
                    <x-primary-button type="button" wire:click="startCreateSale">+ Record Sale</x-primary-button>
                </x-slot:action>
            @endcan
        </x-empty-state>
    @else
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <table class="w-full min-w-[820px] text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Buyer</th>
                        <th class="px-4 py-3 text-right">Quantity</th>
                        <th class="px-4 py-3 text-right">Rate</th>
                        <th class="px-4 py-3 text-right">Amount</th>
                        <th class="px-4 py-3">Bill</th>
                        @can('egg_sales.modify')
                            <th class="px-4 py-3 text-right">Actions</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sales as $sale)
                        <tr wire:key="sale-{{ $sale->id }}" class="border-b border-slate-100 last:border-0 dark:border-slate-700/50">
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-400">{{ $sale->sale_date->format('d M Y') }}</td>
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-900 dark:text-white">{{ $sale->buyer->name }}</span>
                                @if ($sale->remarks)
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $sale->remarks }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">{{ rtrim(rtrim(number_format((float) $sale->quantity, 2), '0'), '.') }}</td>
                            <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">{{ number_format((float) $sale->sale_rate, 2) }}</td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900 dark:text-white">{{ number_format((float) $sale->sale_amount, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                @if ($sale->invoice)
                                    <a href="{{ route('egg-sales.bills.show', $sale->invoice) }}" wire:navigate class="text-brand-600 hover:text-brand-700 hover:underline dark:text-brand-400">{{ $sale->invoice->invoice_number }}</a>
                                @elseif ($sale->buyer->uses_cash_wallet)
                                    <x-badge color="brand">Cash wallet</x-badge>
                                @else
                                    <x-badge color="amber">Not billed</x-badge>
                                @endif
                            </td>
                            @can('egg_sales.modify')
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    @if ($sale->invoice)
                                        <span class="text-xs text-slate-400 dark:text-slate-500">On a bill</span>
                                    @else
                                        <button type="button" wire:click="startEditSale({{ $sale->id }})" class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Edit</button>
                                        <button type="button" wire:click="confirmDeleteSale({{ $sale->id }})" class="ml-3 font-medium text-red-600 hover:text-red-700 dark:text-red-400">Delete</button>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-slate-200 bg-slate-50 font-semibold text-slate-900 dark:border-slate-700 dark:bg-slate-900/50 dark:text-white">
                        <td class="px-4 py-3" colspan="2">Total for these filters</td>
                        <td class="px-4 py-3 text-right">{{ rtrim(rtrim(number_format($filteredQuantity, 2), '0'), '.') }}</td>
                        <td></td>
                        <td class="px-4 py-3 text-right">{{ number_format($filteredAmount, 2) }}</td>
                        <td colspan="{{ Gate::allows('egg_sales.modify') ? 2 : 1 }}"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{ $sales->links() }}
    @endif

    @canany(['egg_sales.create', 'egg_sales.modify'])
    <x-modal name="egg-sale-form" focusable>
        <form wire:submit="saveSale" class="space-y-5 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">{{ $editingSaleId ? 'Edit Sale' : 'Record Sale' }}</h2>

            <div>
                <x-input-label for="sale_date" value="Date" />
                <x-text-input wire:model="sale_date" id="sale_date" type="date" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('sale_date')" class="mt-2" />
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <x-input-label for="buyer_name" value="Buyer" />
                    @if (! $showNewBuyerFields)
                        <button type="button" wire:click="openNewBuyerFields" class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">+ New Buyer</button>
                    @endif
                </div>
                <div
                    class="mt-1"
                    x-data="{
                        open: false,
                        term: '',
                        activeName: null,
                        matches(name) {
                            return this.term === '' || name.toLowerCase().includes(this.term.trim().toLowerCase());
                        },
                        visibleNames() {
                            return [...this.$refs.options.querySelectorAll('[data-buyer]')].map((option) => option.dataset.buyer).filter((name) => this.matches(name));
                        },
                        show() {
                            if (! this.open) {
                                this.term = '';
                                this.activeName = null;
                                this.open = true;
                            }
                        },
                        move(step) {
                            this.show();
                            const names = this.visibleNames();
                            if (names.length) {
                                const index = names.indexOf(this.activeName);
                                this.activeName = names[(index + step + names.length) % names.length];
                            }
                        },
                        choose(name) {
                            this.$refs.input.value = name;
                            this.$wire.set('buyer_name', name);
                            this.open = false;
                        },
                        chooseActive(event) {
                            if (! this.open) {
                                return;
                            }
                            event.preventDefault();
                            const name = this.activeName ?? (this.term !== '' ? this.visibleNames()[0] : null);
                            name ? this.choose(name) : this.open = false;
                        },
                    }"
                    x-on:click.outside="open = false"
                >
                    <div class="relative">
                        <x-text-input
                            wire:model.live.debounce.300ms="buyer_name"
                            id="buyer_name"
                            x-ref="input"
                            role="combobox"
                            aria-autocomplete="list"
                            aria-controls="egg-buyer-options"
                            x-bind:aria-expanded="open"
                            placeholder="Click to choose, or type to search"
                            autocomplete="off"
                            class="block w-full pe-10"
                            required
                            x-on:focus="show()"
                            x-on:click="show()"
                            x-on:input="open = true; term = $event.target.value; activeName = null"
                            x-on:keydown.arrow-down.prevent="move(1)"
                            x-on:keydown.arrow-up.prevent="move(-1)"
                            x-on:keydown.enter="chooseActive($event)"
                            x-on:keydown.tab="open = false"
                            x-on:keydown.escape="if (open) { $event.stopPropagation(); open = false }"
                        />
                        <svg class="pointer-events-none absolute inset-y-0 end-3 my-auto h-4 w-4 text-slate-500 transition" x-bind:class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </div>

                    <ul
                        id="egg-buyer-options"
                        role="listbox"
                        aria-label="Buyers"
                        x-ref="options"
                        x-show="open"
                        x-cloak
                        class="mt-1 max-h-48 overflow-y-auto rounded-md border border-slate-200 bg-white py-1 shadow-sm dark:border-slate-700 dark:bg-slate-900"
                    >
                        @foreach ($activeBuyers as $buyer)
                            <li
                                wire:key="buyer-option-{{ $buyer->id }}"
                                role="option"
                                data-buyer="{{ $buyer->name }}"
                                x-show="matches(@js($buyer->name))"
                                x-bind:aria-selected="activeName === @js($buyer->name)"
                                x-bind:class="activeName === @js($buyer->name) ? 'bg-brand-50 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200' : 'text-slate-700 dark:text-slate-300'"
                                x-on:mousedown.prevent
                                x-on:click="choose(@js($buyer->name))"
                                class="cursor-pointer px-3 py-2 text-sm hover:bg-slate-100 dark:hover:bg-slate-800"
                            >
                                {{ $buyer->name }}
                            </li>
                        @endforeach
                        <li x-show="open && visibleNames().length === 0" class="px-3 py-2 text-sm text-slate-500 dark:text-slate-400">
                            No buyer found. Use + New Buyer to add them.
                        </li>
                    </ul>
                </div>
                <x-input-error :messages="$errors->get('buyer_name')" class="mt-2" />

                @if ($showNewBuyerFields)
                    <div class="mt-3 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900/50">
                        <div>
                            <x-input-label for="new_buyer_name" value="New buyer name" />
                            <x-text-input wire:model="new_buyer_name" id="new_buyer_name" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('new_buyer_name')" class="mt-2" />
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <x-input-label for="new_buyer_phone" value="Phone (optional)" />
                                <x-text-input wire:model="new_buyer_phone" id="new_buyer_phone" class="mt-1 block w-full" />
                            </div>
                            <div>
                                <x-input-label for="new_buyer_address" value="Address (optional)" />
                                <x-text-input wire:model="new_buyer_address" id="new_buyer_address" class="mt-1 block w-full" />
                            </div>
                        </div>
                        <div class="flex justify-end gap-2">
                            <x-secondary-button type="button" wire:click="closeNewBuyerFields">Cancel</x-secondary-button>
                            <x-primary-button type="button" wire:click="addBuyer">Add Buyer</x-primary-button>
                        </div>
                    </div>
                @endif
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="sale_quantity" value="Quantity (eggs)" />
                    <x-text-input wire:model.live.debounce.300ms="sale_quantity" id="sale_quantity" type="number" step="0.01" min="0.01" placeholder="e.g. 100" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('sale_quantity')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="sale_rate" value="Rate (per egg)" />
                    <x-text-input wire:model.live.debounce.300ms="sale_rate" id="sale_rate" type="number" step="0.01" min="0.01" placeholder="e.g. 12.50" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('sale_rate')" class="mt-2" />
                </div>
            </div>

            <div class="flex items-center justify-between rounded-lg bg-slate-50 px-4 py-3 dark:bg-slate-900/50">
                <span class="text-sm text-slate-600 dark:text-slate-400">Amount</span>
                <span class="text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($amountPreview ?? 0, 2) }}</span>
            </div>

            <div>
                <x-input-label for="sale_remarks" value="Remarks (optional)" />
                <x-textarea-input wire:model="sale_remarks" id="sale_remarks" rows="2" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('sale_remarks')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-primary-button>{{ $editingSaleId ? 'Save Changes' : 'Record Sale' }}</x-primary-button>
            </div>
        </form>
    </x-modal>
    @endcanany

    @can('egg_sales.modify')
    <x-modal name="confirm-egg-sale-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this sale?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">It will be removed from your records and egg stock. This cannot be undone.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="deleteSale">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>
    @endcan
</div>
