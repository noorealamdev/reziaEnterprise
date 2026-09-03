<?php

use App\Models\JobEntry;
use App\Models\TiffinItem;
use App\Models\TiffinItemPurchase;
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

    private const SUPPLY_PER_PAGE = 15;

    #[Url(as: 'view', history: true)]
    public string $activeView = 'purchases';

    #[Url(as: 'supply_item', history: true)]
    public string $supplyItemFilter = 'Banana';

    #[Url(as: 'supply_year', history: true)]
    public string $supplyYearFilter = '';

    #[Url(as: 'supply_month', history: true)]
    public string $supplyMonthFilter = '';

    #[Url(as: 'item', history: true)]
    public string $itemFilter = '';

    #[Url(as: 'year', history: true)]
    public string $yearFilter = '';

    #[Url(as: 'month', history: true)]
    public string $monthFilter = '';

    public ?int $editingId = null;

    public ?int $tiffin_item_id = null;

    public string $purchase_date = '';

    public ?string $quantity = null;

    public ?string $cost_rate = null;

    public ?string $supplier_name = null;

    public ?string $remarks = null;

    public string $duplicateNotice = '';

    public ?int $confirmingDeleteId = null;

    public function updatingItemFilter(): void
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

    public function switchView(string $view): void
    {
        $this->activeView = $view;
    }

    public function updatingSupplyItemFilter(): void
    {
        $this->resetPage('supplyPage');
    }

    public function updatingSupplyYearFilter(): void
    {
        // A month only makes sense within a chosen year — clear it if the
        // year changes so the two never disagree.
        $this->supplyMonthFilter = '';
        $this->resetPage('supplyPage');
    }

    public function updatingSupplyMonthFilter(): void
    {
        $this->resetPage('supplyPage');
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->tiffin_item_id = TiffinItem::where('name', 'Egg')->value('id');
        $this->purchase_date = now()->toDateString();
        $this->quantity = null;
        $this->cost_rate = null;
        $this->supplier_name = null;
        $this->remarks = null;
        $this->duplicateNotice = '';
        $this->resetErrorBag();
        // Egg is pre-selected above (it's the only choice), so the usual
        // updated() check — which only fires on an incoming property
        // change — never runs on its own here; run it directly so opening
        // "Record Purchase" on a day that already has one still loads it.
        $this->loadExistingPurchaseIfAny();
        $this->dispatch('open-modal', 'tiffin-purchase-form');
    }

    public function startEdit(int $purchaseId): void
    {
        $purchase = TiffinItemPurchase::findOrFail($purchaseId);
        $this->editingId = $purchase->id;
        $this->tiffin_item_id = $purchase->tiffin_item_id;
        $this->purchase_date = $purchase->purchase_date->format('Y-m-d');
        $this->quantity = (string) $purchase->quantity;
        $this->cost_rate = (string) $purchase->cost_rate;
        $this->supplier_name = $purchase->supplier_name;
        $this->remarks = $purchase->remarks;
        $this->duplicateNotice = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'tiffin-purchase-form');
    }

    /**
     * Picking an item + date that already has a purchase recorded loads it
     * into the form instead of letting the accountant walk into a unique-
     * constraint error — there's only ever one bulk buy per item per day.
     */
    public function updated(string $name): void
    {
        if (in_array($name, ['tiffin_item_id', 'purchase_date'], true)) {
            $this->loadExistingPurchaseIfAny();
        }
    }

    private function loadExistingPurchaseIfAny(): void
    {
        if (! $this->tiffin_item_id || ! $this->purchase_date) {
            return;
        }

        $existing = TiffinItemPurchase::with('tiffinItem')
            ->where('tiffin_item_id', $this->tiffin_item_id)
            ->whereDate('purchase_date', $this->purchase_date)
            ->first();

        if (! $existing || $existing->id === $this->editingId) {
            return;
        }

        $this->editingId = $existing->id;
        $this->quantity = (string) $existing->quantity;
        $this->cost_rate = (string) $existing->cost_rate;
        $this->supplier_name = $existing->supplier_name;
        $this->remarks = $existing->remarks;
        $this->duplicateNotice = "Loaded the existing {$existing->tiffinItem->name} purchase for "
            .$existing->purchase_date->format('d M Y').' — editing it instead of creating a duplicate.';
    }

    public function save(): void
    {
        Gate::authorize($this->editingId ? 'tiffin_purchases.modify' : 'tiffin_purchases.create');

        $validated = $this->validate([
            'tiffin_item_id' => ['required', 'integer', 'exists:tiffin_items,id'],
            'purchase_date' => [
                'required', 'date',
                Rule::unique('tiffin_item_purchases', 'purchase_date')
                    ->where(fn ($query) => $query->where('tiffin_item_id', $this->tiffin_item_id))
                    ->ignore($this->editingId),
            ],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'cost_rate' => ['required', 'numeric', 'min:0'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['supplier_name'] = $validated['supplier_name'] ? trim($validated['supplier_name']) : null;
        $validated['remarks'] = $validated['remarks'] ? trim($validated['remarks']) : null;
        $validated['cost_amount'] = round((float) $validated['quantity'] * (float) $validated['cost_rate'], 2);

        if ($this->editingId) {
            TiffinItemPurchase::whereKey($this->editingId)->update($validated);
        } else {
            $validated['created_by'] = auth()->id();
            TiffinItemPurchase::create($validated);
        }

        $this->dispatch('close-modal', 'tiffin-purchase-form');
        session()->flash('status', $this->editingId ? 'Purchase updated.' : 'Purchase recorded.');
    }

    public function confirmDelete(int $purchaseId): void
    {
        $this->confirmingDeleteId = $purchaseId;
        $this->dispatch('open-modal', 'confirm-tiffin-purchase-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('tiffin_purchases.modify');

        if ($this->confirmingDeleteId) {
            TiffinItemPurchase::destroy($this->confirmingDeleteId);
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-tiffin-purchase-deletion');
        session()->flash('status', 'Purchase deleted.');
    }

    public function with(): array
    {
        // Fetched as plain dates rather than a raw SQL YEAR() aggregate so
        // this stays portable between MySQL (prod) and SQLite (tests).
        $availableYears = TiffinItemPurchase::pluck('purchase_date')
            ->map(fn ($date) => $date->year)
            ->unique()
            ->sortDesc()
            ->values();

        $monthOptions = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(2000, $m, 1)->format('F')]);

        return [
            // Only Egg's cost fluctuates day to day and needs a purchase
            // record locking Tiffin's cost rate — Banana and Bread don't.
            'items' => TiffinItem::where('is_active', true)->where('name', 'Egg')->orderBy('name')->get(),
            'purchases' => TiffinItemPurchase::with('tiffinItem')
                ->when($this->itemFilter, fn ($query) => $query->where('tiffin_item_id', $this->itemFilter))
                ->when($this->yearFilter, fn ($query) => $query->whereYear('purchase_date', $this->yearFilter))
                ->when($this->monthFilter, fn ($query) => $query->whereMonth('purchase_date', $this->monthFilter))
                ->orderByDesc('purchase_date')
                ->orderByDesc('id')
                ->simplePaginate(10),
            'availableYears' => $availableYears,
            'monthOptions' => $monthOptions,
            'costAmountPreview' => (is_numeric($this->quantity) && is_numeric($this->cost_rate))
                ? round((float) $this->quantity * (float) $this->cost_rate, 2)
                : null,
            ...$this->supplyReport(),
        ];
    }

    /**
     * How much of one Tiffin item was actually supplied across every
     * company on each day — not a cost-rate lock like the Purchases tab,
     * but the real quantity used, so the office can reconcile what a
     * supplier (e.g. the Banana vendor) says they delivered against what
     * every company's job entries actually recorded, and know what to pay
     * them monthly.
     *
     * @return array{supplyItems: Collection<int, string>, supplyAvailableYears: Collection<int, int>, supplyMonthOptions: Collection<int, string>, supplyDays: LengthAwarePaginator, supplyMonthlyTotal: float, supplyScopeLabel: string}
     */
    private function supplyReport(): array
    {
        $supplyItems = JobEntry::whereNotNull('tiffin_department_id')
            ->distinct()
            ->orderBy('supply_type')
            ->pluck('supply_type');

        $itemEntries = JobEntry::whereNotNull('tiffin_department_id')
            ->where('supply_type', $this->supplyItemFilter)
            ->get(['entry_date', 'quantity']);

        // Offered years always reflect this item's whole history, not just
        // what the current year/month filter leaves behind — same
        // convention as every other Year/Month filter in the app.
        $supplyAvailableYears = $itemEntries->map(fn (JobEntry $entry) => $entry->entry_date->year)->unique()->sortDesc()->values();

        $filteredEntries = $itemEntries
            ->when($this->supplyYearFilter, fn ($rows) => $rows->filter(fn (JobEntry $entry) => $entry->entry_date->year == $this->supplyYearFilter))
            ->when($this->supplyMonthFilter, fn ($rows) => $rows->filter(fn (JobEntry $entry) => $entry->entry_date->month == $this->supplyMonthFilter));

        $allDays = $filteredEntries
            ->groupBy(fn (JobEntry $entry) => $entry->entry_date->toDateString())
            ->map(fn ($group) => [
                'date' => $group->first()->entry_date,
                'quantity' => (float) $group->sum('quantity'),
            ])
            ->sortByDesc('date')
            ->values();

        $supplyDays = new LengthAwarePaginator(
            $allDays->forPage($this->getPage('supplyPage'), self::SUPPLY_PER_PAGE)->values(),
            $allDays->count(),
            self::SUPPLY_PER_PAGE,
            $this->getPage('supplyPage'),
            ['pageName' => 'supplyPage']
        );

        $scopeLabel = match (true) {
            (bool) $this->supplyYearFilter && (bool) $this->supplyMonthFilter => Carbon::create((int) $this->supplyYearFilter, (int) $this->supplyMonthFilter, 1)->format('F Y'),
            (bool) $this->supplyYearFilter => (string) $this->supplyYearFilter,
            default => 'all-time',
        };

        return [
            'supplyItems' => $supplyItems,
            'supplyAvailableYears' => $supplyAvailableYears,
            'supplyMonthOptions' => collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(2000, $m, 1)->format('F')]),
            'supplyDays' => $supplyDays,
            'supplyMonthlyTotal' => (float) $filteredEntries->sum('quantity'),
            'supplyScopeLabel' => $scopeLabel,
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Tiffin Purchases</h2>
        @can('tiffin_purchases.create')
            @if ($activeView === 'purchases')
                <x-primary-button type="button" wire:click="startCreate">
                    + Record Purchase
                </x-primary-button>
            @endif
        @endcan
    </div>

    <div class="flex flex-wrap gap-2 border-b border-slate-200 dark:border-slate-700">
        <button
            type="button"
            wire:click="switchView('purchases')"
            class="border-b-2 px-1 pb-2 text-sm font-medium {{ $activeView === 'purchases' ? 'border-brand-600 text-brand-600 dark:border-brand-400 dark:text-brand-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
        >
            Purchases
        </button>
        <button
            type="button"
            wire:click="switchView('supply')"
            class="border-b-2 px-1 pb-2 text-sm font-medium {{ $activeView === 'supply' ? 'border-brand-600 text-brand-600 dark:border-brand-400 dark:text-brand-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
        >
            Supply by Item
        </button>
    </div>

    @if ($activeView === 'purchases')
        <p class="text-xs text-slate-500 dark:text-slate-400">
            One bulk buy per item per day — this is what Tiffin's cost rate is locked to when a matching
            purchase exists for that item and date.
        </p>

        <div class="flex flex-wrap items-center gap-3">
            <x-select-input wire:model.live="itemFilter" class="w-full sm:w-48">
                <option value="">All items</option>
                @foreach ($items as $item)
                    <option value="{{ $item->id }}">{{ $item->name }}</option>
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
        </div>

        @forelse ($purchases as $purchase)
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $purchase->tiffinItem->name }}</h3>
                            <span class="text-xs text-slate-400 dark:text-slate-500">{{ $purchase->purchase_date->format('d M Y') }}</span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Qty {{ rtrim(rtrim(number_format((float) $purchase->quantity, 2), '0'), '.') }}
                            · Rate {{ number_format((float) $purchase->cost_rate, 2) }}
                            @if ($purchase->supplier_name)
                                · {{ $purchase->supplier_name }}
                            @endif
                        </p>
                    </div>
                    <span class="shrink-0 text-sm font-semibold text-slate-900 dark:text-white">{{ number_format((float) $purchase->cost_amount, 2) }}</span>
                </div>

                @can('tiffin_purchases.modify')
                    <div class="mt-4 flex items-center gap-3">
                        <x-secondary-button type="button" wire:click="startEdit({{ $purchase->id }})">
                            Edit
                        </x-secondary-button>
                        <x-danger-button type="button" wire:click="confirmDelete({{ $purchase->id }})">
                            Delete
                        </x-danger-button>
                    </div>
                @endcan
            </div>
        @empty
            <x-empty-state
                :title="$availableYears->isNotEmpty() ? 'No purchases match these filters' : 'No purchases recorded yet'"
                :message="$availableYears->isNotEmpty() ? 'Try a different item, year or month — or clear the filters above.' : 'Record today\'s Egg purchase to lock in its cost rate for every Tiffin entry today.'"
            />
        @endforelse

        {{ $purchases->links('pagination::simple-tailwind') }}
    @else
        <p class="text-xs text-slate-500 dark:text-slate-400">
            How much of one item was supplied across every company each day — not a cost-rate lock,
            the real quantity used, so you can reconcile against what the supplier says they delivered
            and know what to pay them.
        </p>

        <div class="flex flex-wrap items-center gap-3">
            <x-select-input wire:model.live="supplyItemFilter" class="w-full sm:w-48">
                @forelse ($supplyItems as $item)
                    <option value="{{ $item }}">{{ $item }}</option>
                @empty
                    <option value="{{ $supplyItemFilter }}">{{ $supplyItemFilter }}</option>
                @endforelse
            </x-select-input>

            <x-select-input wire:model.live="supplyYearFilter" class="w-full sm:w-32">
                <option value="">Every year</option>
                @foreach ($supplyAvailableYears as $year)
                    <option value="{{ $year }}">{{ $year }}</option>
                @endforeach
            </x-select-input>

            <x-select-input wire:model.live="supplyMonthFilter" class="w-full sm:w-40" :disabled="! $supplyYearFilter">
                <option value="">{{ $supplyYearFilter ? 'Every month' : 'Pick a year first' }}</option>
                @foreach ($supplyMonthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select-input>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Total {{ $supplyItemFilter }} — {{ $supplyScopeLabel }}</p>
            <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">
                {{ rtrim(rtrim(number_format($supplyMonthlyTotal, 2), '0'), '.') }}
            </p>
        </div>

        @if ($supplyDays->isEmpty())
            <x-empty-state
                :title="$supplyAvailableYears->isNotEmpty() ? 'No supply matches these filters' : 'No '.$supplyItemFilter.' recorded yet'"
                :message="$supplyAvailableYears->isNotEmpty() ? 'Try a different item, year or month — or clear the filters above.' : 'It will show up here once a Tiffin batch entry uses this item.'"
            />
        @else
            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <table class="w-full min-w-[320px] text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                            <th class="px-4 py-2">Date</th>
                            <th class="px-4 py-2 text-right">Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($supplyDays as $day)
                            <tr class="border-b border-slate-100 last:border-0 dark:border-slate-700/50">
                                <td class="px-4 py-3 text-slate-700 dark:text-slate-300">{{ $day['date']->format('d M Y') }}</td>
                                <td class="px-4 py-3 text-right font-medium text-slate-800 dark:text-slate-200">{{ rtrim(rtrim(number_format($day['quantity'], 2), '0'), '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $supplyDays->links('pagination::simple-tailwind') }}
        @endif
    @endif

    <x-modal name="tiffin-purchase-form" focusable>
        <form wire:submit="save" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingId ? 'Edit Purchase' : 'Record Purchase' }}
            </h2>

            @if ($duplicateNotice)
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                    {{ $duplicateNotice }}
                </p>
            @endif

            <div>
                <x-input-label for="purchase_item" value="Item" />
                <x-select-input wire:model.live="tiffin_item_id" id="purchase_item" class="mt-1 block w-full" required>
                    <option value="">Select an item…</option>
                    @foreach ($items as $item)
                        <option value="{{ $item->id }}">{{ $item->name }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('tiffin_item_id')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="purchase_date" value="Purchase Date" />
                <x-text-input wire:model.live="purchase_date" id="purchase_date" type="date" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('purchase_date')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="purchase_quantity" value="Quantity" />
                <x-text-input wire:model.live.debounce.400ms="quantity" id="purchase_quantity" type="number" step="0.01" min="0" placeholder="e.g. 500" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('quantity')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="purchase_cost_rate" value="Cost Rate" />
                <x-text-input wire:model.live.debounce.400ms="cost_rate" id="purchase_cost_rate" type="number" step="0.01" min="0" placeholder="e.g. 11.50" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('cost_rate')" class="mt-2" />
            </div>

            @if ($costAmountPreview !== null)
                <p class="text-sm text-slate-600 dark:text-slate-400">Cost Amount: <span class="font-semibold text-slate-900 dark:text-white">{{ number_format($costAmountPreview, 2) }}</span></p>
            @endif

            <div>
                <x-input-label for="purchase_supplier" value="Supplier Name" />
                <x-text-input wire:model="supplier_name" id="purchase_supplier" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('supplier_name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="purchase_remarks" value="Remarks" />
                <x-textarea-input wire:model="remarks" id="purchase_remarks" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('remarks')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-primary-button>
                    {{ $editingId ? 'Save Changes' : 'Record Purchase' }}
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-tiffin-purchase-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this purchase?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                This cannot be undone. Tiffin entries already saved with this rate keep their own figures — deleting
                this purchase only affects new entries going forward.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="delete">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
