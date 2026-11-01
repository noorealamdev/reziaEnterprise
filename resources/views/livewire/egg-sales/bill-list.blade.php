<?php

use App\Models\EggBuyer;
use App\Models\EggInvoice;
use App\Models\EggInvoicePayment;
use App\Models\EggSale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'bill_buyer', history: true)]
    public string $buyerFilter = '';

    #[Url(as: 'bill_status', history: true)]
    public string $statusFilter = '';

    #[Url(as: 'bill_year', history: true)]
    public string $yearFilter = '';

    public ?int $confirmingBuyerId = null;

    public string $confirmingMonth = '';

    public string $billPeriodType = 'month';

    public string $billFrom = '';

    public string $billTo = '';

    public function updatingBuyerFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingYearFilter(): void
    {
        $this->resetPage();
    }

    public function confirmGenerate(int $buyerId, string $month): void
    {
        $this->confirmingBuyerId = $buyerId;
        $this->confirmingMonth = $month;
        $this->billPeriodType = 'month';
        $this->fillCustomDatesFromMonth();
        $this->dispatch('open-modal', 'confirm-egg-bill');
    }

    public function updatedConfirmingMonth(): void
    {
        $this->fillCustomDatesFromMonth();
    }

    private function fillCustomDatesFromMonth(): void
    {
        $period = $this->monthPeriod();

        $this->billFrom = $period ? $period[0]->toDateString() : '';
        $this->billTo = $period ? $period[1]->toDateString() : '';
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function monthPeriod(): ?array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->confirmingMonth)) {
            return null;
        }

        $start = Carbon::createFromFormat('Y-m-d', "{$this->confirmingMonth}-01")->startOfDay();

        return [$start, $start->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * The dates chosen in the popup, or null while they aren't a valid range.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function selectedPeriod(): ?array
    {
        if ($this->billPeriodType !== 'custom') {
            return $this->monthPeriod();
        }

        $datesAreValid = Validator::make(
            ['from' => $this->billFrom, 'to' => $this->billTo],
            ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']],
        )->passes();

        return $datesAreValid
            ? [Carbon::parse($this->billFrom)->startOfDay(), Carbon::parse($this->billTo)->startOfDay()]
            : null;
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}  $period
     * @return Builder<EggSale>
     */
    private function unbilledSalesIn(int $buyerId, array $period): Builder
    {
        return EggSale::query()
            ->where('egg_buyer_id', $buyerId)
            ->whereNull('egg_invoice_id')
            ->whereDate('sale_date', '>=', $period[0]->toDateString())
            ->whereDate('sale_date', '<=', $period[1]->toDateString());
    }

    /**
     * What the popup's Generate button would bill right now. A sale outside
     * the chosen dates simply stays in Ready to Bill.
     */
    private function billPreview(): ?object
    {
        $period = $this->selectedPeriod();

        if (! $this->confirmingBuyerId || ! $period) {
            return null;
        }

        $sales = $this->unbilledSalesIn($this->confirmingBuyerId, $period)->get(['quantity', 'sale_amount']);
        $unbilledForBuyer = EggSale::where('egg_buyer_id', $this->confirmingBuyerId)->whereNull('egg_invoice_id')->count();

        return (object) [
            'periodLabel' => (new EggInvoice(['period_start' => $period[0], 'period_end' => $period[1]]))->periodLabel,
            'count' => $sales->count(),
            'quantity' => (float) $sales->sum('quantity'),
            'total' => (float) $sales->sum('sale_amount'),
            'leftOut' => $unbilledForBuyer - $sales->count(),
        ];
    }

    /**
     * Collects whatever is unbilled for this buyer and period at the moment of
     * clicking, so a sale added later simply goes onto another bill.
     */
    public function generateBill(): void
    {
        Gate::authorize('egg_sales.create');

        $buyer = EggBuyer::find($this->confirmingBuyerId);

        if (! $buyer) {
            return;
        }

        if ($buyer->uses_cash_wallet) {
            $this->dispatch('close-modal', 'confirm-egg-bill');
            $this->dispatch('toast', message: "{$buyer->name} uses a cash wallet and is not billed.", type: 'error');

            return;
        }

        $period = $this->selectedPeriod();

        if (! $period) {
            $this->dispatch('toast', message: 'Choose a valid From and To date.', type: 'error');

            return;
        }

        [$start, $end] = $period;

        $bill = DB::transaction(function () use ($buyer, $period, $start, $end) {
            $saleIds = $this->unbilledSalesIn($buyer->id, $period)
                ->lockForUpdate()
                ->pluck('id');

            if ($saleIds->isEmpty()) {
                return null;
            }

            $bill = EggInvoice::create([
                'egg_buyer_id' => $buyer->id,
                'invoice_number' => EggInvoice::nextNumberFor($buyer, $start),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'status' => 'due',
                'created_by' => auth()->id(),
            ]);

            EggSale::whereIn('id', $saleIds)->update(['egg_invoice_id' => $bill->id]);

            return $bill;
        });

        $this->dispatch('close-modal', 'confirm-egg-bill');

        if (! $bill) {
            $this->dispatch('toast', message: 'There are no unbilled sales left for this buyer in these dates.', type: 'error');

            return;
        }

        session()->flash('status', "Bill {$bill->invoice_number} created.");
        $this->redirect(route('egg-sales.bills.show', $bill), navigate: true);
    }

    /**
     * @return Collection<int, object>
     */
    private function readyToBill(): Collection
    {
        return EggSale::with('buyer:id,name')
            ->whereNull('egg_invoice_id')
            ->whereRelation('buyer', 'uses_cash_wallet', false)
            ->get(['id', 'egg_buyer_id', 'sale_date', 'quantity', 'sale_amount'])
            ->groupBy(fn (EggSale $sale) => $sale->egg_buyer_id.'|'.$sale->sale_date->format('Y-m'))
            ->map(fn (Collection $sales) => (object) [
                'buyerId' => $sales->first()->egg_buyer_id,
                'buyerName' => $sales->first()->buyer->name,
                'month' => $sales->first()->sale_date->format('Y-m'),
                'monthLabel' => $sales->first()->sale_date->format('F Y'),
                'count' => $sales->count(),
                'quantity' => (float) $sales->sum('quantity'),
                'total' => (float) $sales->sum('sale_amount'),
            ])
            ->sortBy([['month', 'asc'], ['buyerName', 'asc']])
            ->values();
    }

    public function with(): array
    {
        $readyToBill = $this->readyToBill();

        return [
            'readyToBill' => $readyToBill,
            'confirmingBuyerName' => EggBuyer::whereKey($this->confirmingBuyerId)->value('name'),
            'billableMonths' => $readyToBill->where('buyerId', $this->confirmingBuyerId)->values(),
            'billPreview' => $this->billPreview(),
            'bills' => EggInvoice::query()
                ->with('buyer:id,name')
                ->withSum('sales as total_amount', 'sale_amount')
                ->withSum('payments as paid_amount', 'amount')
                ->when($this->buyerFilter, fn ($query) => $query->where('egg_buyer_id', $this->buyerFilter))
                ->when(in_array($this->statusFilter, ['due', 'partial', 'paid'], true), fn ($query) => $query->where('status', $this->statusFilter))
                ->when($this->statusFilter === 'unpaid', fn ($query) => $query->whereIn('status', ['due', 'partial']))
                ->when($this->yearFilter, fn ($query) => $query->whereYear('period_start', $this->yearFilter))
                ->orderByDesc('period_start')
                ->orderByDesc('id')
                ->paginate(25),
            'buyerOptions' => EggBuyer::where('uses_cash_wallet', false)->orderBy('name')->get(['id', 'name']),
            'availableYears' => EggInvoice::pluck('period_start')->map(fn (Carbon $date) => $date->year)->unique()->sortDesc()->values(),
            'totalBilled' => (float) EggSale::whereNotNull('egg_invoice_id')->sum('sale_amount'),
            'totalPaid' => (float) EggInvoicePayment::sum('amount'),
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Total billed</p>
            <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($totalBilled, 2) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Total collected</p>
            <p class="mt-1 text-lg font-semibold text-emerald-700 dark:text-emerald-400">{{ number_format($totalPaid, 2) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Still owed on bills</p>
            <p class="mt-1 text-lg font-semibold {{ $totalBilled - $totalPaid > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">{{ number_format(max(0, $totalBilled - $totalPaid), 2) }}</p>
        </div>
    </div>

    <section>
        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Ready to Bill</h3>
        <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">Sales that aren't on a bill yet, one row per buyer and month. You can choose another month or custom dates when generating.</p>

        @if ($readyToBill->isEmpty())
            <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-500 shadow-sm dark:border-slate-800 dark:bg-slate-800 dark:text-slate-400">
                You're all caught up. Every sale is on a bill.
            </div>
        @else
            <div class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white shadow-sm dark:divide-slate-700/50 dark:border-slate-800 dark:bg-slate-800">
                @foreach ($readyToBill as $row)
                    <div wire:key="ready-{{ $row->buyerId }}-{{ $row->month }}" class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $row->buyerName }} <span class="font-normal text-slate-500 dark:text-slate-400">· {{ $row->monthLabel }}</span></p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $row->count }} {{ Str::plural('sale', $row->count) }} · {{ rtrim(rtrim(number_format($row->quantity, 2), '0'), '.') }} eggs</p>
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ number_format($row->total, 2) }}</span>
                            @can('egg_sales.create')
                                <x-primary-button type="button" wire:click="confirmGenerate({{ $row->buyerId }}, '{{ $row->month }}')">Generate Bill</x-primary-button>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">All Bills</h3>
            <div class="flex w-full flex-wrap gap-3 sm:w-auto">
                <x-select-input wire:model.live="buyerFilter" aria-label="Buyer" class="w-full sm:w-44">
                    <option value="">Every buyer</option>
                    @foreach ($buyerOptions as $buyer)
                        <option value="{{ $buyer->id }}">{{ $buyer->name }}</option>
                    @endforeach
                </x-select-input>
                <x-select-input wire:model.live="statusFilter" aria-label="Status" class="w-full sm:w-44">
                    <option value="">Every status</option>
                    <option value="unpaid">Unpaid (Due + Partial)</option>
                    <option value="due">Due</option>
                    <option value="partial">Partially Paid</option>
                    <option value="paid">Paid</option>
                </x-select-input>
                <x-select-input wire:model.live="yearFilter" aria-label="Year" class="w-full sm:w-32">
                    <option value="">Every year</option>
                    @foreach ($availableYears as $year)
                        <option value="{{ $year }}">{{ $year }}</option>
                    @endforeach
                </x-select-input>
            </div>
        </div>

        @if ($bills->isEmpty())
            <x-empty-state title="No bills found" message="Generate a bill from the Ready to Bill list above." />
        @else
            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <table class="w-full min-w-[820px] text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                            <th class="px-4 py-3">Bill</th>
                            <th class="px-4 py-3">Buyer</th>
                            <th class="px-4 py-3">Period</th>
                            <th class="px-4 py-3 text-right">Total</th>
                            <th class="px-4 py-3 text-right">Paid</th>
                            <th class="px-4 py-3 text-right">Balance</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bills as $bill)
                            @php $balance = max(0, (float) $bill->total_amount - (float) $bill->paid_amount); @endphp
                            <tr wire:key="bill-{{ $bill->id }}" class="border-b border-slate-100 last:border-0 dark:border-slate-700/50">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <a href="{{ route('egg-sales.bills.show', $bill) }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-700 hover:underline dark:text-brand-400">{{ $bill->invoice_number }}</a>
                                </td>
                                <td class="px-4 py-3 font-medium text-slate-900 dark:text-white">{{ $bill->buyer->name }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-400">{{ $bill->periodLabel }}</td>
                                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">{{ number_format((float) $bill->total_amount, 2) }}</td>
                                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">{{ number_format((float) $bill->paid_amount, 2) }}</td>
                                <td class="px-4 py-3 text-right font-semibold {{ $balance > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">{{ number_format($balance, 2) }}</td>
                                <td class="px-4 py-3">
                                    <x-badge :color="match ($bill->status) { 'paid' => 'green', 'partial' => 'brand', default => 'amber' }">
                                        {{ $bill->status === 'partial' ? 'Partially Paid' : ucfirst($bill->status) }}
                                    </x-badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $bills->links() }}
        @endif
    </section>

    @can('egg_sales.create')
        <x-modal name="confirm-egg-bill" focusable>
            <div class="p-6">
                <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Generate Bill</h2>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Buyer: <strong>{{ $confirmingBuyerName }}</strong></p>

                <div class="mt-4 inline-flex flex-wrap gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-900/50">
                    @foreach (['month' => 'Whole Month', 'custom' => 'Custom Dates'] as $type => $label)
                        <button
                            type="button"
                            wire:click="$set('billPeriodType', '{{ $type }}')"
                            aria-pressed="{{ $billPeriodType === $type ? 'true' : 'false' }}"
                            class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $billPeriodType === $type ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                @if ($billPeriodType === 'custom')
                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="bill_from" value="From" />
                            <x-text-input wire:model.live="billFrom" id="bill_from" type="date" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label for="bill_to" value="To" />
                            <x-text-input wire:model.live="billTo" id="bill_to" type="date" class="mt-1 block w-full" />
                        </div>
                    </div>
                @else
                    <div class="mt-4">
                        <x-input-label for="bill_month" value="Month" />
                        <x-select-input wire:model.live="confirmingMonth" id="bill_month" class="mt-1 block w-full">
                            @foreach ($billableMonths as $row)
                                <option value="{{ $row->month }}">{{ $row->monthLabel }} ({{ $row->count }} {{ Str::plural('sale', $row->count) }})</option>
                            @endforeach
                        </x-select-input>
                    </div>
                @endif

                <div id="egg-bill-preview" class="mt-4 rounded-lg bg-slate-50 p-3 dark:bg-slate-900/50">
                    @if (! $billPreview)
                        <p class="text-sm text-red-600 dark:text-red-400">Choose a From and To date. To can't be before From.</p>
                    @elseif ($billPreview->count === 0)
                        <p class="text-sm text-slate-600 dark:text-slate-400">This buyer has no unbilled sales in {{ $billPreview->periodLabel }}.</p>
                    @else
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $billPreview->periodLabel }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $billPreview->count }} {{ Str::plural('sale', $billPreview->count) }} · {{ rtrim(rtrim(number_format($billPreview->quantity, 2), '0'), '.') }} eggs</p>
                            </div>
                            <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($billPreview->total, 2) }}</p>
                        </div>
                    @endif
                </div>

                @if ($billPreview && $billPreview->leftOut > 0)
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ $billPreview->leftOut }} other unbilled {{ Str::plural('sale', $billPreview->leftOut) }} for this buyer will stay in Ready to Bill.</p>
                @endif
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">These sales can't be edited unless the bill is deleted. A sale added later goes on another bill.</p>

                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                    <x-primary-button
                        type="button"
                        wire:click="generateBill"
                        wire:loading.attr="disabled"
                        wire:target="generateBill"
                        :disabled="! $billPreview || $billPreview->count === 0"
                        class="disabled:cursor-not-allowed disabled:opacity-50"
                    >Generate Bill</x-primary-button>
                </div>
            </div>
        </x-modal>
    @endcan
</div>
