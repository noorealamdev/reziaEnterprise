<?php

use App\Models\EggBuyer;
use App\Models\EggBuyerPayment;
use App\Models\EggSale;
use Illuminate\Pagination\LengthAwarePaginator;
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

    private const SALES_PER_PAGE = 20;

    #[Url(as: 'view', history: true)]
    public string $activeView = 'sales';

    #[Url(as: 'year', history: true)]
    public string $saleYearFilter = '';

    #[Url(as: 'month', history: true)]
    public string $saleMonthFilter = '';

    /** Quick range: '' (use Year/Month), '7' (last 7 days) or '15' (last 15 days). */
    #[Url(as: 'range', history: true)]
    public string $saleRangeFilter = '';

    #[Url(as: 'buyer', history: true)]
    public string $saleBuyerFilter = '';

    #[Url(as: 'in_charge', history: true)]
    public string $saleInChargeFilter = '';

    #[Url(as: 'summary_year', history: true)]
    public string $summaryYearFilter = '';

    #[Url(as: 'summary_month', history: true)]
    public string $summaryMonthFilter = '';

    public ?int $editingSaleId = null;

    public string $sale_date = '';

    public ?string $sale_quantity = null;

    public ?string $sale_rate = null;

    public ?int $egg_buyer_id = null;

    public string $payment_status = 'cash';

    public ?string $in_charge = null;

    public ?string $sale_remarks = null;

    public ?int $confirmingDeleteSaleId = null;

    public ?int $payingBuyerId = null;

    public ?string $buyerPayAmount = null;

    public string $buyerPayDate = '';

    public ?string $buyerPayRemarks = null;

    public ?int $confirmingDeleteBuyerPaymentId = null;

    /** Revealed by "+ New Buyer" inside the sale form, instead of a nested modal. */
    public bool $showNewBuyerFields = false;

    public ?string $new_buyer_name = null;

    public ?string $new_buyer_phone = null;

    /** Revealed by "Edit" next to the buyer dropdown, same inline pattern as "+ New". */
    public bool $showEditBuyerFields = false;

    public ?string $edit_buyer_name = null;

    public ?string $edit_buyer_phone = null;

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

    public function switchView(string $view): void
    {
        $this->activeView = $view;
    }

    public function updatingSaleYearFilter(): void
    {
        $this->saleMonthFilter = '';
        $this->saleRangeFilter = '';
        $this->resetPage('salesPage');
    }

    public function updatingSaleMonthFilter(): void
    {
        $this->resetPage('salesPage');
    }

    public function updatingSaleRangeFilter(): void
    {
        $this->saleYearFilter = '';
        $this->saleMonthFilter = '';
        $this->resetPage('salesPage');
    }

    public function updatingSaleBuyerFilter(): void
    {
        $this->resetPage('salesPage');
    }

    public function updatingSaleInChargeFilter(): void
    {
        $this->resetPage('salesPage');
    }

    public function updatingSummaryYearFilter(): void
    {
        $this->summaryMonthFilter = '';
    }

    /**
     * Every #[Url]-bound property this component has, appended onto both
     * paginators' page links — without this, clicking "Next" (a plain
     * <a href>, not a wire:click) does a full page reload to a URL holding
     * only the page number, silently dropping the active tab and every
     * other filter back to their defaults.
     *
     * @return array<string, string>
     */
    private function urlQueryState(): array
    {
        return array_filter([
            'view' => $this->activeView,
            'year' => $this->saleYearFilter,
            'month' => $this->saleMonthFilter,
            'range' => $this->saleRangeFilter,
            'buyer' => $this->saleBuyerFilter,
            'in_charge' => $this->saleInChargeFilter,
            'summary_year' => $this->summaryYearFilter,
            'summary_month' => $this->summaryMonthFilter,
        ], fn ($value) => $value !== '');
    }

    public function startCreateSale(): void
    {
        $this->editingSaleId = null;
        $this->sale_date = now()->toDateString();
        $this->sale_quantity = null;
        $this->sale_rate = null;
        $this->egg_buyer_id = null;
        $this->payment_status = 'cash';
        $this->in_charge = null;
        $this->sale_remarks = null;
        $this->showNewBuyerFields = false;
        $this->new_buyer_name = null;
        $this->new_buyer_phone = null;
        $this->showEditBuyerFields = false;
        $this->edit_buyer_name = null;
        $this->edit_buyer_phone = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-sale-form');
    }

    public function startEditSale(int $saleId): void
    {
        $sale = EggSale::findOrFail($saleId);
        $this->editingSaleId = $sale->id;
        $this->sale_date = $sale->sale_date->format('Y-m-d');
        $this->sale_quantity = (string) $sale->quantity;
        $this->sale_rate = (string) $sale->sale_rate;
        $this->egg_buyer_id = $sale->egg_buyer_id;
        // Editing only ever offers Cash/Due — "Paid" is a legacy status from
        // before per-sale marking was removed in favor of the Bill payment
        // system, so an old paid sale still shows as Due here rather than
        // silently defaulting back to Cash.
        $this->payment_status = $sale->payment_status === 'paid' ? 'due' : $sale->payment_status;
        $this->in_charge = $sale->in_charge;
        $this->sale_remarks = $sale->remarks;
        $this->showNewBuyerFields = false;
        $this->new_buyer_name = null;
        $this->new_buyer_phone = null;
        $this->showEditBuyerFields = false;
        $this->edit_buyer_name = null;
        $this->edit_buyer_phone = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-sale-form');
    }

    /**
     * Creates the buyer right from the sale form instead of a separate
     * nested modal — the office almost always meets a new buyer while
     * recording their first sale, not ahead of time.
     */
    public function addBuyerInline(): void
    {
        Gate::authorize($this->editingSaleId ? 'egg_sales.modify' : 'egg_sales.create');

        $validated = $this->validate([
            'new_buyer_name' => ['required', 'string', 'max:255', Rule::unique('egg_buyers', 'name')],
            'new_buyer_phone' => ['nullable', 'string', 'max:255'],
        ]);

        $buyer = EggBuyer::create([
            'name' => trim($validated['new_buyer_name']),
            'phone' => $validated['new_buyer_phone'] ? trim($validated['new_buyer_phone']) : null,
            'created_by' => auth()->id(),
        ]);

        $this->egg_buyer_id = $buyer->id;
        $this->showNewBuyerFields = false;
        $this->new_buyer_name = null;
        $this->new_buyer_phone = null;
    }

    /**
     * Renames the currently-selected buyer in place — fixes a typo or a
     * name that's changed, right from the sale form, without needing a
     * separate page just for buyer management.
     */
    public function startEditBuyerInline(): void
    {
        if (! $this->egg_buyer_id) {
            return;
        }

        $buyer = EggBuyer::find($this->egg_buyer_id);
        $this->edit_buyer_name = $buyer?->name;
        $this->edit_buyer_phone = $buyer?->phone;
        $this->showEditBuyerFields = true;
    }

    public function saveBuyerInline(): void
    {
        Gate::authorize($this->editingSaleId ? 'egg_sales.modify' : 'egg_sales.create');

        $validated = $this->validate([
            'edit_buyer_name' => ['required', 'string', 'max:255', Rule::unique('egg_buyers', 'name')->ignore($this->egg_buyer_id)],
            'edit_buyer_phone' => ['nullable', 'string', 'max:255'],
        ]);

        EggBuyer::whereKey($this->egg_buyer_id)->update([
            'name' => trim($validated['edit_buyer_name']),
            'phone' => $validated['edit_buyer_phone'] ? trim($validated['edit_buyer_phone']) : null,
        ]);

        $this->showEditBuyerFields = false;
        $this->edit_buyer_name = null;
        $this->edit_buyer_phone = null;
    }

    public function saveSale(): void
    {
        Gate::authorize($this->editingSaleId ? 'egg_sales.modify' : 'egg_sales.create');

        $validated = $this->validate([
            'sale_date' => ['required', 'date'],
            'sale_quantity' => ['required', 'numeric', 'min:0.01'],
            'sale_rate' => ['required', 'numeric', 'min:0'],
            'egg_buyer_id' => ['nullable', 'integer', 'exists:egg_buyers,id'],
            'payment_status' => ['required', Rule::in(['cash', 'due'])],
            'in_charge' => ['nullable', 'string', 'max:255'],
            'sale_remarks' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'sale_quantity' => 'quantity',
            'sale_rate' => 'sale rate',
        ]);

        $attributes = [
            'sale_date' => $validated['sale_date'],
            'quantity' => $validated['sale_quantity'],
            'sale_rate' => $validated['sale_rate'],
            'sale_amount' => round((float) $validated['sale_quantity'] * (float) $validated['sale_rate'], 2),
            'egg_buyer_id' => $validated['egg_buyer_id'],
            'payment_status' => $validated['payment_status'],
            'in_charge' => $validated['in_charge'] ? trim($validated['in_charge']) : null,
            'remarks' => $validated['sale_remarks'] ? trim($validated['sale_remarks']) : null,
        ];

        if ($this->editingSaleId) {
            EggSale::whereKey($this->editingSaleId)->update($attributes);
        } else {
            $attributes['created_by'] = auth()->id();
            EggSale::create($attributes);
        }

        $this->dispatch('close-modal', 'egg-sale-form');
        session()->flash('status', $this->editingSaleId ? 'Sale updated.' : 'Sale recorded.');
    }

    public function confirmDeleteSale(int $saleId): void
    {
        $this->confirmingDeleteSaleId = $saleId;
        $this->dispatch('open-modal', 'confirm-egg-sale-deletion');
    }

    public function deleteSale(): void
    {
        Gate::authorize('egg_sales.modify');

        if ($this->confirmingDeleteSaleId) {
            EggSale::whereKey($this->confirmingDeleteSaleId)->delete();
        }

        $this->confirmingDeleteSaleId = null;
        $this->dispatch('close-modal', 'confirm-egg-sale-deletion');
        session()->flash('status', 'Sale deleted.');
    }

    /**
     * Opens the payment form for one buyer, pre-filled with whatever they
     * currently owe overall (not scoped to the period being viewed — a
     * buyer's real debt doesn't reset each month) so settling in full is a
     * one-field confirm, same UX as Company Purchases' "Pay Cash" and
     * Invoices' "Record Payment".
     */
    public function startRecordBuyerPayment(int $eggBuyerId): void
    {
        $buyer = EggBuyer::findOrFail($eggBuyerId);
        $this->payingBuyerId = $buyer->id;
        $outstanding = $buyer->outstandingDue;
        $this->buyerPayAmount = $outstanding > 0 ? rtrim(rtrim(number_format($outstanding, 2, '.', ''), '0'), '.') : '';
        $this->buyerPayDate = now()->toDateString();
        $this->buyerPayRemarks = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-buyer-payment-form');
    }

    /**
     * Records cash actually received from a buyer against their running
     * balance — usable repeatedly, so a buyer who only ever pays part of
     * what they owe can still be tracked accurately over time.
     */
    public function recordBuyerPayment(): void
    {
        Gate::authorize('egg_sales.modify');

        $buyer = EggBuyer::findOrFail($this->payingBuyerId);

        $validated = $this->validate([
            'buyerPayAmount' => [
                'required', 'numeric', 'min:0.01',
                function (string $attribute, $value, $fail) use ($buyer): void {
                    if ((float) $value > $buyer->outstandingDue + 0.01) {
                        $fail('This buyer only owes '.number_format($buyer->outstandingDue, 2).' right now.');
                    }
                },
            ],
            'buyerPayDate' => ['required', 'date'],
            'buyerPayRemarks' => ['nullable', 'string', 'max:2000'],
        ]);

        EggBuyerPayment::create([
            'egg_buyer_id' => $buyer->id,
            'amount' => (float) $validated['buyerPayAmount'],
            'paid_on' => $validated['buyerPayDate'],
            'remarks' => $validated['buyerPayRemarks'] ? trim($validated['buyerPayRemarks']) : null,
            'created_by' => auth()->id(),
        ]);

        $this->buyerPayAmount = null;
        $this->buyerPayRemarks = null;
        $this->dispatch('close-modal', 'egg-buyer-payment-form');
        session()->flash('status', 'Payment recorded.');
    }

    public function confirmDeleteBuyerPayment(int $paymentId): void
    {
        $this->confirmingDeleteBuyerPaymentId = $paymentId;
        // Closes the payment form first — a confirm dialog opening on top of
        // an already-open modal isn't a pattern used anywhere else in this
        // app, so this keeps only one modal ever visible at a time.
        $this->dispatch('close-modal', 'egg-buyer-payment-form');
        $this->dispatch('open-modal', 'confirm-egg-buyer-payment-deletion');
    }

    public function deleteBuyerPayment(): void
    {
        Gate::authorize('egg_sales.modify');

        if ($this->confirmingDeleteBuyerPaymentId) {
            EggBuyerPayment::whereKey($this->confirmingDeleteBuyerPaymentId)->delete();
        }

        $this->confirmingDeleteBuyerPaymentId = null;
        $this->dispatch('close-modal', 'confirm-egg-buyer-payment-deletion');
        session()->flash('status', 'Payment removed.');
    }

    /**
     * Jumps to the Sales tab filtered to exactly this buyer and period —
     * the itemized data behind one Buyer Summary row, one click away.
     */
    public function viewBuyerSales(int $eggBuyerId): void
    {
        $this->saleBuyerFilter = (string) $eggBuyerId;
        $this->saleYearFilter = $this->summaryYearFilter;
        $this->saleMonthFilter = $this->summaryMonthFilter;
        $this->saleRangeFilter = '';
        $this->activeView = 'sales';
    }

    public function with(): array
    {
        $monthOptions = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(2000, $m, 1)->format('F')]);

        return [
            'buyers' => EggBuyer::orderBy('name')->get(),
            'saleAmountPreview' => (is_numeric($this->sale_quantity) && is_numeric($this->sale_rate))
                ? round((float) $this->sale_quantity * (float) $this->sale_rate, 2)
                : null,
            ...$this->salesReport($monthOptions),
            ...$this->buyerSalesSummary($monthOptions),
            'payingBuyer' => $this->payingBuyerId
                ? EggBuyer::with(['payments' => fn ($query) => $query->orderByDesc('paid_on')->orderByDesc('id')])->find($this->payingBuyerId)
                : null,
        ];
    }

    /**
     * @param  Collection<int, string>  $monthOptions
     * @return array{sales: LengthAwarePaginator, saleAvailableYears: Collection<int, int>, saleMonthOptions: Collection<int, string>}
     */
    private function salesReport(Collection $monthOptions): array
    {
        $saleAvailableYears = EggSale::pluck('sale_date')
            ->map(fn ($date) => $date->year)
            ->unique()
            ->sortDesc()
            ->values();

        $sales = EggSale::with('buyer')
            ->when($this->saleRangeFilter, fn ($query) => $query->whereDate('sale_date', '>=', now()->subDays((int) $this->saleRangeFilter - 1)->startOfDay()->toDateString()))
            ->when($this->saleYearFilter, fn ($query) => $query->whereYear('sale_date', $this->saleYearFilter))
            ->when($this->saleMonthFilter, fn ($query) => $query->whereMonth('sale_date', $this->saleMonthFilter))
            ->when($this->saleBuyerFilter, fn ($query) => $query->where('egg_buyer_id', $this->saleBuyerFilter))
            ->when($this->saleInChargeFilter, fn ($query) => $query->where('in_charge', 'like', '%'.$this->saleInChargeFilter.'%'))
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->simplePaginate(self::SALES_PER_PAGE, ['*'], 'salesPage')
            ->setPath($this->paginationPath)
            ->appends($this->urlQueryState());

        return [
            'sales' => $sales,
            'saleAvailableYears' => $saleAvailableYears,
            'saleMonthOptions' => $monthOptions,
        ];
    }

    /**
     * The complete cash/due picture: who eggs were sold to, how much of
     * that is cash-in-hand versus still owed, grouped by buyer within
     * whatever Year/Month is selected — this is the real monthly data the
     * office hands to (or chases) a buyer with, no separate bill document
     * needed. Grouped by buyer id (not name) so "View" and "Record Payment"
     * below can target the right buyer even if two ever share a name.
     *
     * @param  Collection<int, string>  $monthOptions
     * @return array{buyerSummaries: Collection<int, object>, summaryAvailableYears: Collection<int, int>, summaryMonthOptions: Collection<int, string>}
     */
    private function buyerSalesSummary(Collection $monthOptions): array
    {
        $summaryAvailableYears = EggSale::pluck('sale_date')
            ->map(fn ($date) => $date->year)
            ->unique()
            ->sortDesc()
            ->values();

        $sales = EggSale::with('buyer')
            ->when($this->summaryYearFilter, fn ($query) => $query->whereYear('sale_date', $this->summaryYearFilter))
            ->when($this->summaryMonthFilter, fn ($query) => $query->whereMonth('sale_date', $this->summaryMonthFilter))
            ->get();

        // The client collects payment from buyers every month, so this is
        // scoped to whatever Year/Month the table itself is filtered to, by
        // the payment's own paid_on date: "how much did we actually collect
        // from each buyer this period" — deliberately different scoping
        // from outstandingDue below, which stays all-time on purpose.
        $paymentsThisPeriod = EggBuyerPayment::when($this->summaryYearFilter, fn ($query) => $query->whereYear('paid_on', $this->summaryYearFilter))
            ->when($this->summaryMonthFilter, fn ($query) => $query->whereMonth('paid_on', $this->summaryMonthFilter))
            ->get();
        $paymentsThisPeriodByBuyer = $paymentsThisPeriod->groupBy('egg_buyer_id')->map(fn ($payments) => (float) $payments->sum('amount'));

        // A buyer's real outstanding balance spans every Due sale they've
        // ever had minus every payment ever recorded — not just this period
        // — so it's fetched separately, in two batched queries rather than
        // EggBuyer::outstandingDue per row, to avoid an N+1. The buyer id
        // list includes anyone who paid this period even without a sale
        // this period (settling a prior month's due), so that payment is
        // never silently missing from the table.
        $buyerIds = $sales->pluck('egg_buyer_id')->merge($paymentsThisPeriodByBuyer->keys())->filter()->unique()->values();
        $allTimeDueByBuyer = EggSale::where('payment_status', 'due')
            ->whereIn('egg_buyer_id', $buyerIds)
            ->selectRaw('egg_buyer_id, SUM(sale_amount) as total')
            ->groupBy('egg_buyer_id')
            ->pluck('total', 'egg_buyer_id');
        $paymentsReceivedByBuyer = EggBuyerPayment::whereIn('egg_buyer_id', $buyerIds)
            ->selectRaw('egg_buyer_id, SUM(amount) as total')
            ->groupBy('egg_buyer_id')
            ->pluck('total', 'egg_buyer_id');

        $buildSummary = function (?int $buyerId, Collection $sales) use ($allTimeDueByBuyer, $paymentsReceivedByBuyer, $paymentsThisPeriodByBuyer) {
            return (object) [
                'buyerId' => $buyerId,
                'buyerName' => $sales->first()?->buyer?->name ?: ($buyerId ? EggBuyer::find($buyerId)?->name : null) ?: 'Unnamed Buyer',
                'quantity' => (float) $sales->sum('quantity'),
                'cashAmount' => (float) $sales->where('payment_status', 'cash')->sum('sale_amount'),
                'dueAmount' => (float) $sales->where('payment_status', 'due')->sum('sale_amount'),
                // Legacy sales flipped Paid the old way (none in real data
                // anymore) plus cash actually received via the payment
                // ledger this period — the two are mutually exclusive, so
                // summing them can't double-count.
                'paidAmount' => (float) $sales->where('payment_status', 'paid')->sum('sale_amount')
                    + (float) ($paymentsThisPeriodByBuyer[$buyerId] ?? 0),
                'totalAmount' => (float) $sales->sum('sale_amount'),
                'outstandingDue' => $buyerId
                    ? max(0.0, (float) ($allTimeDueByBuyer[$buyerId] ?? 0) - (float) ($paymentsReceivedByBuyer[$buyerId] ?? 0))
                    : 0.0,
            ];
        };

        $salesByBuyer = $sales->groupBy('egg_buyer_id');
        $buyerSummaries = $salesByBuyer->map(fn (Collection $sales, $buyerId) => $buildSummary($buyerId !== '' ? (int) $buyerId : null, $sales));

        // A buyer who paid this period but has no sale at all this period
        // (settling a prior month's balance) still needs their own row.
        foreach ($paymentsThisPeriodByBuyer->keys()->diff($salesByBuyer->keys()) as $buyerId) {
            $buyerSummaries->push($buildSummary($buyerId, collect()));
        }

        $buyerSummaries = $buyerSummaries->sortBy('buyerName')->values();

        // A friendly label for whatever period is currently selected, so
        // the "Received" figure never has to be read next to the filter
        // dropdowns to know which month it's for — useful on its own in a
        // screenshot or printout too. Null (nothing selected) shows no
        // label at all rather than a redundant "All Time".
        $summaryPeriodLabel = match (true) {
            (bool) $this->summaryYearFilter && (bool) $this->summaryMonthFilter => Carbon::create((int) $this->summaryYearFilter, (int) $this->summaryMonthFilter, 1)->format('F Y'),
            (bool) $this->summaryYearFilter => (string) $this->summaryYearFilter,
            default => null,
        };

        return [
            'buyerSummaries' => $buyerSummaries,
            'summaryAvailableYears' => $summaryAvailableYears,
            'summaryMonthOptions' => $monthOptions,
            'summaryPeriodLabel' => $summaryPeriodLabel,
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Egg Sales</h2>
        @can('egg_sales.create')
            @if ($activeView === 'sales')
                <x-primary-button type="button" wire:click="startCreateSale">
                    + Record Sale
                </x-primary-button>
            @endif
        @endcan
    </div>

    <div class="inline-flex flex-wrap gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800/60">
        <button
            type="button"
            wire:click="switchView('sales')"
            class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $activeView === 'sales' ? 'bg-white text-brand-700 shadow-sm dark:bg-slate-700 dark:text-brand-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19V5m5 14V9m5 10V13m5 6V7" /></svg>
            Sales
        </button>
        <button
            type="button"
            wire:click="switchView('buyer-summary')"
            class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $activeView === 'buyer-summary' ? 'bg-white text-brand-700 shadow-sm dark:bg-slate-700 dark:text-brand-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a4 4 0 0 1 3-3.87m6-1.13a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm6 0a4 4 0 1 0 0-8" /></svg>
            Buyer Summary
        </button>
    </div>

    @if ($activeView === 'sales')
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Eggs sold to an outside buyer, separate from Tiffin's own internal supply (that's already fully
            covered by the factory's normal Tiffin invoice) — each sale reduces the egg stock on hand.
        </p>

        <div class="flex flex-wrap items-center gap-3">
            <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-800">
                @foreach (['' => 'All time', '7' => '7 Days', '15' => '15 Days', '30' => '30 Days'] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('saleRangeFilter', '{{ $value }}')"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ $saleRangeFilter === (string) $value ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <x-select-input wire:model.live="saleYearFilter" class="w-full sm:w-32">
                <option value="">Every year</option>
                @foreach ($saleAvailableYears as $year)
                    <option value="{{ $year }}">{{ $year }}</option>
                @endforeach
            </x-select-input>

            <x-select-input wire:model.live="saleMonthFilter" class="w-full sm:w-40" :disabled="! $saleYearFilter">
                <option value="">{{ $saleYearFilter ? 'Every month' : 'Pick a year first' }}</option>
                @foreach ($saleMonthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select-input>

            <x-select-input wire:model.live="saleBuyerFilter" class="w-full sm:w-48">
                <option value="">Every buyer</option>
                @foreach ($buyers as $buyer)
                    <option value="{{ $buyer->id }}">{{ $buyer->name }}</option>
                @endforeach
            </x-select-input>

            <x-text-input wire:model.live.debounce.400ms="saleInChargeFilter" placeholder="Search by In-Charge" class="w-full sm:w-48" />
        </div>

        @forelse ($sales as $sale)
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $sale->buyer?->name ?: 'Egg Sale' }}</h3>
                            <span class="text-xs text-slate-400 dark:text-slate-500">{{ $sale->sale_date->format('d M Y') }}</span>
                            @if ($sale->payment_status === 'due')
                                <x-badge color="amber">Due</x-badge>
                            @elseif ($sale->payment_status === 'paid')
                                <x-badge color="green">Paid</x-badge>
                            @else
                                <x-badge color="slate">Cash</x-badge>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Qty {{ rtrim(rtrim(number_format((float) $sale->quantity, 2), '0'), '.') }}
                            · Rate {{ number_format((float) $sale->sale_rate, 2) }}
                            @if ($sale->in_charge)
                                · In-Charge: {{ $sale->in_charge }}
                            @endif
                        </p>
                        @if ($sale->remarks)
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $sale->remarks }}</p>
                        @endif
                    </div>
                    <span class="shrink-0 text-sm font-semibold text-slate-900 dark:text-white">{{ number_format((float) $sale->sale_amount, 2) }}</span>
                </div>

                @can('egg_sales.modify')
                    <div class="mt-4 flex items-center gap-3">
                        <x-secondary-button type="button" wire:click="startEditSale({{ $sale->id }})">
                            Edit
                        </x-secondary-button>
                        <x-danger-button type="button" wire:click="confirmDeleteSale({{ $sale->id }})">
                            Delete
                        </x-danger-button>
                    </div>
                @endcan
            </div>
        @empty
            <x-empty-state
                :title="$saleAvailableYears->isNotEmpty() ? 'No sales match these filters' : 'No Egg sales recorded yet'"
                :message="$saleAvailableYears->isNotEmpty() ? 'Try a different year, month or buyer — or clear the filters above.' : 'Record a sale whenever eggs are sold to an outside buyer.'"
            />
        @endforelse

        {{ $sales->links('pagination::simple-tailwind') }}
    @elseif ($activeView === 'buyer-summary')
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Who eggs were sold to this period, how much of that is cash-in-hand versus still owed, and how
            much was actually collected from each buyer this period — pick a Year and Month to see exactly
            one month's worth of buyer activity and collections.
        </p>

        <div class="flex flex-wrap items-center gap-3">
            <x-select-input wire:model.live="summaryYearFilter" class="w-full sm:w-32">
                <option value="">Every year</option>
                @foreach ($summaryAvailableYears as $year)
                    <option value="{{ $year }}">{{ $year }}</option>
                @endforeach
            </x-select-input>

            <x-select-input wire:model.live="summaryMonthFilter" class="w-full sm:w-40" :disabled="! $summaryYearFilter">
                <option value="">{{ $summaryYearFilter ? 'Every month' : 'Pick a year first' }}</option>
                @foreach ($summaryMonthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select-input>
        </div>

        @if ($buyerSummaries->isEmpty())
            <x-empty-state
                title="No sales or payments match these filters"
                message="Try a different year or month — or clear the filters above."
            />
        @else
            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <table class="w-full min-w-[560px] text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                            <th class="px-4 py-2">Buyer</th>
                            <th class="px-4 py-2 text-right">Quantity</th>
                            <th class="px-4 py-2 text-right">Cash</th>
                            <th class="px-4 py-2 text-right">Due</th>
                            <th class="px-4 py-2 text-right">
                                Received
                                @if ($summaryPeriodLabel)
                                    <span class="block text-[10px] font-normal normal-case text-slate-400 dark:text-slate-500">{{ $summaryPeriodLabel }}</span>
                                @endif
                            </th>
                            <th class="px-4 py-2 text-right">Total</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($buyerSummaries as $summary)
                            <tr class="border-b border-slate-100 last:border-0 dark:border-slate-700/50">
                                <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-200">
                                    {{ $summary->buyerName }}
                                    @if ($summary->outstandingDue > 0)
                                        <p class="mt-0.5 text-xs font-normal text-red-600 dark:text-red-400">Owes {{ number_format($summary->outstandingDue, 2) }} overall</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">{{ rtrim(rtrim(number_format($summary->quantity, 2), '0'), '.') }}</td>
                                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">{{ number_format($summary->cashAmount, 2) }}</td>
                                <td class="px-4 py-3 text-right {{ $summary->dueAmount > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-600 dark:text-slate-400' }}">{{ number_format($summary->dueAmount, 2) }}</td>
                                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">{{ number_format($summary->paidAmount, 2) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-900 dark:text-white">{{ number_format($summary->totalAmount, 2) }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @if ($summary->buyerId)
                                        <button type="button" wire:click="viewBuyerSales({{ $summary->buyerId }})" class="text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                                            View
                                        </button>
                                        @can('egg_sales.modify')
                                            @if ($summary->outstandingDue > 0)
                                                <button type="button" wire:click="startRecordBuyerPayment({{ $summary->buyerId }})" class="ml-3 text-xs font-medium text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300">
                                                    Record Payment
                                                </button>
                                            @endif
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-slate-50 dark:bg-slate-900/50">
                            <td class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400">Total</td>
                            <td class="px-4 py-2 text-right text-sm font-medium text-slate-600 dark:text-slate-400">{{ rtrim(rtrim(number_format($buyerSummaries->sum('quantity'), 2), '0'), '.') }}</td>
                            <td class="px-4 py-2 text-right text-sm font-medium text-slate-600 dark:text-slate-400">{{ number_format($buyerSummaries->sum('cashAmount'), 2) }}</td>
                            <td class="px-4 py-2 text-right text-sm font-medium text-red-600 dark:text-red-400">{{ number_format($buyerSummaries->sum('dueAmount'), 2) }}</td>
                            <td class="px-4 py-2 text-right text-sm font-medium text-slate-600 dark:text-slate-400">{{ number_format($buyerSummaries->sum('paidAmount'), 2) }}</td>
                            <td class="px-4 py-2 text-right text-sm font-bold text-slate-900 dark:text-white">{{ number_format($buyerSummaries->sum('totalAmount'), 2) }}</td>
                            <td class="px-4 py-2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    @endif

    <x-modal name="egg-sale-form" focusable>
        <form wire:submit="saveSale" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingSaleId ? 'Edit Sale' : 'Record Sale' }}
            </h2>

            <div>
                <x-input-label for="sale_date" value="Sale Date" />
                <x-text-input wire:model="sale_date" id="sale_date" type="date" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('sale_date')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="sale_quantity" value="Quantity" />
                <x-text-input wire:model.live.debounce.400ms="sale_quantity" id="sale_quantity" type="number" step="0.01" min="0" placeholder="e.g. 100" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('sale_quantity')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="sale_rate" value="Sale Rate" />
                <x-text-input wire:model.live.debounce.400ms="sale_rate" id="sale_rate" type="number" step="0.01" min="0" placeholder="e.g. 14.00" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('sale_rate')" class="mt-2" />
            </div>

            @if ($saleAmountPreview !== null)
                <p class="text-sm text-slate-600 dark:text-slate-400">Sale Amount: <span class="font-semibold text-slate-900 dark:text-white">{{ number_format($saleAmountPreview, 2) }}</span></p>
            @endif

            <div>
                <x-input-label for="egg_buyer_id" value="Buyer" />

                @if ($showEditBuyerFields)
                    <div class="mt-1 space-y-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <x-text-input wire:model="edit_buyer_name" placeholder="Buyer name" class="block w-full" />
                        <x-input-error :messages="$errors->get('edit_buyer_name')" class="mt-1" />
                        <x-text-input wire:model="edit_buyer_phone" placeholder="Phone (optional)" class="block w-full" />
                        <div class="flex items-center gap-3">
                            <x-secondary-button type="button" wire:click="saveBuyerInline">Save Buyer</x-secondary-button>
                            <button type="button" wire:click="$set('showEditBuyerFields', false)" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">Cancel</button>
                        </div>
                    </div>
                @elseif (! $showNewBuyerFields)
                    <div class="mt-1 flex items-center gap-2">
                        <x-select-input wire:model="egg_buyer_id" id="egg_buyer_id" class="block w-full">
                            <option value="">Select a buyer…</option>
                            @foreach ($buyers as $buyer)
                                <option value="{{ $buyer->id }}">{{ $buyer->name }}</option>
                            @endforeach
                        </x-select-input>
                        @if ($egg_buyer_id)
                            <button type="button" wire:click="startEditBuyerInline" class="shrink-0 text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                                Edit
                            </button>
                        @endif
                        <button type="button" wire:click="$set('showNewBuyerFields', true)" class="shrink-0 text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                            + New
                        </button>
                    </div>
                @else
                    <div class="mt-1 space-y-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <x-text-input wire:model="new_buyer_name" placeholder="Buyer name" class="block w-full" />
                        <x-input-error :messages="$errors->get('new_buyer_name')" class="mt-1" />
                        <x-text-input wire:model="new_buyer_phone" placeholder="Phone (optional)" class="block w-full" />
                        <div class="flex items-center gap-3">
                            <x-secondary-button type="button" wire:click="addBuyerInline">Add Buyer</x-secondary-button>
                            <button type="button" wire:click="$set('showNewBuyerFields', false)" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">Cancel</button>
                        </div>
                    </div>
                @endif
                <x-input-error :messages="$errors->get('egg_buyer_id')" class="mt-2" />
            </div>

            <div>
                <x-input-label value="Payment" />
                <div class="mt-1 flex gap-4">
                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="radio" wire:model="payment_status" value="cash" class="text-brand-600 focus:ring-brand-500">
                        Cash
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="radio" wire:model="payment_status" value="due" class="text-brand-600 focus:ring-brand-500">
                        Due
                    </label>
                </div>
                <x-input-error :messages="$errors->get('payment_status')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="in_charge" value="In-Charge" />
                <x-text-input wire:model="in_charge" id="in_charge" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('in_charge')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="sale_remarks" value="Remarks" />
                <x-textarea-input wire:model="sale_remarks" id="sale_remarks" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('sale_remarks')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-primary-button>
                    {{ $editingSaleId ? 'Save Changes' : 'Record Sale' }}
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-egg-sale-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this sale?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">This cannot be undone.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="deleteSale">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="egg-buyer-payment-form" focusable>
        <form wire:submit="recordBuyerPayment" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                Record Payment{{ $payingBuyer ? ' — '.$payingBuyer->name : '' }}
            </h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                Cash actually received from this buyer against what they owe overall — use this for a partial
                payment too, not just a full settlement.
            </p>

            @if ($payingBuyer)
                <p class="text-sm text-slate-600 dark:text-slate-400">
                    Currently owes: <span class="font-semibold text-slate-900 dark:text-white">{{ number_format($payingBuyer->outstandingDue, 2) }}</span>
                </p>
            @endif

            <div>
                <x-input-label for="buyerPayAmount" value="Amount" />
                <x-text-input wire:model="buyerPayAmount" id="buyerPayAmount" type="number" step="0.01" min="0.01" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('buyerPayAmount')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="buyerPayDate" value="Date" />
                <x-text-input wire:model="buyerPayDate" id="buyerPayDate" type="date" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('buyerPayDate')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="buyerPayRemarks" value="Remarks" />
                <x-textarea-input wire:model="buyerPayRemarks" id="buyerPayRemarks" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('buyerPayRemarks')" class="mt-2" />
            </div>

            @if ($payingBuyer && $payingBuyer->payments->isNotEmpty())
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Payment History</p>
                    <div class="mt-2 max-h-48 space-y-1 overflow-y-auto pr-1">
                        @foreach ($payingBuyer->payments as $payment)
                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 px-2 py-1 text-xs dark:bg-slate-900/50">
                                <span class="text-slate-600 dark:text-slate-400">
                                    {{ number_format((float) $payment->amount, 2) }} — {{ $payment->paid_on->format('d M Y') }}
                                    @if ($payment->remarks)
                                        · {{ $payment->remarks }}
                                    @endif
                                </span>
                                @can('egg_sales.modify')
                                    <button type="button" wire:click="confirmDeleteBuyerPayment({{ $payment->id }})" class="font-medium text-red-400 hover:text-red-600 dark:text-red-400/80 dark:hover:text-red-300">
                                        Remove
                                    </button>
                                @endcan
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-primary-button>
                    Record Payment
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-egg-buyer-payment-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Remove this payment?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                This buyer's outstanding balance will go back up by this amount. This cannot be undone.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="deleteBuyerPayment">Remove</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
