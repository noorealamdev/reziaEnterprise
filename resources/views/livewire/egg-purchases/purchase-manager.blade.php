<?php

use App\Models\EggSale;
use App\Models\EggWaste;
use App\Models\JobEntry;
use App\Models\TiffinItem;
use App\Models\TiffinItemPurchase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use WithFileUploads;
    use WithPagination;

    private const SUPPLY_PER_PAGE = 15;

    private const SALES_PER_PAGE = 10;

    private const WASTE_PER_PAGE = 10;

    #[Url(as: 'view', history: true)]
    public string $activeView = 'purchases';

    #[Url(as: 'supply_item', history: true)]
    public string $supplyItemFilter = 'Banana';

    #[Url(as: 'supply_year', history: true)]
    public string $supplyYearFilter = '';

    #[Url(as: 'supply_month', history: true)]
    public string $supplyMonthFilter = '';

    /** Quick range: '' (use Year/Month), '7' (last 7 days) or '15' (last 15 days). */
    #[Url(as: 'supply_range', history: true)]
    public string $supplyRangeFilter = '';

    #[Url(as: 'item', history: true)]
    public string $itemFilter = '';

    #[Url(as: 'year', history: true)]
    public string $yearFilter = '';

    #[Url(as: 'month', history: true)]
    public string $monthFilter = '';

    /** Quick range: '' (use Year/Month), '7' (last 7 days) or '15' (last 15 days). */
    #[Url(as: 'range', history: true)]
    public string $rangeFilter = '';

    #[Url(as: 'sale_year', history: true)]
    public string $saleYearFilter = '';

    #[Url(as: 'sale_month', history: true)]
    public string $saleMonthFilter = '';

    /** Quick range: '' (use Year/Month), '7' (last 7 days) or '15' (last 15 days). */
    #[Url(as: 'sale_range', history: true)]
    public string $saleRangeFilter = '';

    #[Url(as: 'sale_in_charge', history: true)]
    public string $saleInChargeFilter = '';

    #[Url(as: 'waste_year', history: true)]
    public string $wasteYearFilter = '';

    #[Url(as: 'waste_month', history: true)]
    public string $wasteMonthFilter = '';

    /** Quick range: '' (use Year/Month), '7' (last 7 days), '15' or '30'. */
    #[Url(as: 'waste_range', history: true)]
    public string $wasteRangeFilter = '';

    public ?int $editingId = null;

    public ?int $tiffin_item_id = null;

    public string $purchase_date = '';

    public ?string $quantity = null;

    public ?string $cost_rate = null;

    public ?string $supplier_name = null;

    public ?string $remarks = null;

    public string $duplicateNotice = '';

    public ?int $confirmingDeleteId = null;

    /** The memo already saved against the purchase being edited, if any. */
    public ?string $existingMemoPath = null;

    /** A newly chosen file, staged until save() persists it. */
    public $memoFile = null;

    /** Set when the accountant removes an existing memo without replacing it. */
    public bool $removeMemo = false;

    public ?int $editingSaleId = null;

    public string $sale_date = '';

    public ?string $sale_quantity = null;

    public ?string $sale_rate = null;

    public ?string $buyer_name = null;

    public string $payment_status = 'cash';

    public ?string $in_charge = null;

    public ?string $sale_remarks = null;

    public ?int $confirmingDeleteSaleId = null;

    public ?int $editingWasteId = null;

    public string $waste_date = '';

    public ?string $waste_quantity = null;

    public ?string $waste_remarks = null;

    public ?int $confirmingDeleteWasteId = null;

    /**
     * Captured once in mount() — a paginator built or re-resolved mid-session
     * would otherwise take its path from request()->url(), which resolves to
     * Livewire's own update endpoint during an AJAX re-render (e.g.
     * switching tabs), not this page's real URL.
     */
    public string $paginationPath = '';

    public function mount(): void
    {
        $this->paginationPath = request()->url();
    }

    public function updatingItemFilter(): void
    {
        $this->resetPage();
    }

    public function updatingYearFilter(): void
    {
        // A month only makes sense within a chosen year — clear it if the
        // year changes so the two never disagree. The quick range filter is
        // a separate, mutually exclusive way to scope the same list.
        $this->monthFilter = '';
        $this->rangeFilter = '';
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
        // year changes so the two never disagree. The quick range filter is
        // a separate, mutually exclusive way to scope the same report.
        $this->supplyMonthFilter = '';
        $this->supplyRangeFilter = '';
        $this->resetPage('supplyPage');
    }

    public function updatingSupplyMonthFilter(): void
    {
        $this->resetPage('supplyPage');
    }

    public function updatingSupplyRangeFilter(): void
    {
        $this->supplyYearFilter = '';
        $this->supplyMonthFilter = '';
        $this->resetPage('supplyPage');
    }

    public function updatingSaleYearFilter(): void
    {
        // A month only makes sense within a chosen year — clear it if the
        // year changes so the two never disagree. The quick range filter is
        // a separate, mutually exclusive way to scope the same report.
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

    public function updatingSaleInChargeFilter(): void
    {
        $this->resetPage('salesPage');
    }

    public function updatingWasteYearFilter(): void
    {
        $this->wasteMonthFilter = '';
        $this->wasteRangeFilter = '';
        $this->resetPage('wastePage');
    }

    public function updatingWasteMonthFilter(): void
    {
        $this->resetPage('wastePage');
    }

    public function updatingWasteRangeFilter(): void
    {
        $this->wasteYearFilter = '';
        $this->wasteMonthFilter = '';
        $this->resetPage('wastePage');
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
            'supply_item' => $this->supplyItemFilter,
            'supply_year' => $this->supplyYearFilter,
            'supply_month' => $this->supplyMonthFilter,
            'supply_range' => $this->supplyRangeFilter,
            'item' => $this->itemFilter,
            'year' => $this->yearFilter,
            'month' => $this->monthFilter,
            'range' => $this->rangeFilter,
            'sale_year' => $this->saleYearFilter,
            'sale_month' => $this->saleMonthFilter,
            'sale_range' => $this->saleRangeFilter,
            'sale_in_charge' => $this->saleInChargeFilter,
            'waste_year' => $this->wasteYearFilter,
            'waste_month' => $this->wasteMonthFilter,
            'waste_range' => $this->wasteRangeFilter,
        ], fn ($value) => $value !== '');
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
        $this->existingMemoPath = null;
        $this->memoFile = null;
        $this->removeMemo = false;
        $this->duplicateNotice = '';
        $this->resetErrorBag();
        // Egg is pre-selected above (it's the only choice), so the usual
        // updated() check — which only fires on an incoming property
        // change — never runs on its own here; run it directly so opening
        // "Record Purchase" on a day that already has one still loads it.
        $this->loadExistingPurchaseIfAny();
        $this->dispatch('open-modal', 'egg-purchase-form');
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
        $this->existingMemoPath = $purchase->memo_path;
        $this->memoFile = null;
        $this->removeMemo = false;
        $this->duplicateNotice = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-purchase-form');
    }

    /**
     * Marks the currently-loaded memo for removal on save() without
     * requiring a replacement — the preview disappears immediately so the
     * accountant can see the change took, but nothing is deleted from
     * storage until Save is actually pressed.
     */
    public function clearMemo(): void
    {
        $this->existingMemoPath = null;
        $this->removeMemo = true;
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
        $this->existingMemoPath = $existing->memo_path;
        $this->memoFile = null;
        $this->removeMemo = false;
        $this->duplicateNotice = "Loaded the existing {$existing->tiffinItem->name} purchase for "
            .$existing->purchase_date->format('d M Y').' — editing it instead of creating a duplicate.';
    }

    public function save(): void
    {
        Gate::authorize($this->editingId ? 'egg_purchases.modify' : 'egg_purchases.create');

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
            'memoFile' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['supplier_name'] = $validated['supplier_name'] ? trim($validated['supplier_name']) : null;
        $validated['remarks'] = $validated['remarks'] ? trim($validated['remarks']) : null;
        $validated['cost_amount'] = round((float) $validated['quantity'] * (float) $validated['cost_rate'], 2);

        $existingPurchase = $this->editingId ? TiffinItemPurchase::find($this->editingId) : null;

        unset($validated['memoFile']);

        if ($this->memoFile) {
            if ($existingPurchase?->memo_path) {
                Storage::disk('public')->delete($existingPurchase->memo_path);
            }
            $validated['memo_path'] = $this->memoFile->store('purchase-memos', 'public');
        } elseif ($this->removeMemo) {
            if ($existingPurchase?->memo_path) {
                Storage::disk('public')->delete($existingPurchase->memo_path);
            }
            $validated['memo_path'] = null;
        }

        if ($this->editingId) {
            TiffinItemPurchase::whereKey($this->editingId)->update($validated);
        } else {
            $validated['created_by'] = auth()->id();
            TiffinItemPurchase::create($validated);
        }

        $this->memoFile = null;
        $this->removeMemo = false;
        $this->dispatch('close-modal', 'egg-purchase-form');
        session()->flash('status', $this->editingId ? 'Purchase updated.' : 'Purchase recorded.');
    }

    public function confirmDelete(int $purchaseId): void
    {
        $this->confirmingDeleteId = $purchaseId;
        $this->dispatch('open-modal', 'confirm-egg-purchase-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('egg_purchases.modify');

        if ($this->confirmingDeleteId) {
            $purchase = TiffinItemPurchase::find($this->confirmingDeleteId);

            if ($purchase?->memo_path) {
                Storage::disk('public')->delete($purchase->memo_path);
            }

            $purchase?->delete();
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-egg-purchase-deletion');
        session()->flash('status', 'Purchase deleted.');
    }

    public function startCreateSale(): void
    {
        $this->editingSaleId = null;
        $this->sale_date = now()->toDateString();
        $this->sale_quantity = null;
        $this->sale_rate = null;
        $this->buyer_name = null;
        $this->payment_status = 'cash';
        $this->in_charge = null;
        $this->sale_remarks = null;
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
        $this->buyer_name = $sale->buyer_name;
        // Editing only ever offers Cash/Due — "Paid" is reached exclusively
        // through markSalePaid() below, never typed in directly, so a
        // previously-paid sale still shows as Due here rather than
        // silently defaulting back to Cash.
        $this->payment_status = $sale->payment_status === 'paid' ? 'due' : $sale->payment_status;
        $this->in_charge = $sale->in_charge;
        $this->sale_remarks = $sale->remarks;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-sale-form');
    }

    public function saveSale(): void
    {
        Gate::authorize($this->editingSaleId ? 'egg_sales.modify' : 'egg_sales.create');

        $validated = $this->validate([
            'sale_date' => ['required', 'date'],
            'sale_quantity' => ['required', 'numeric', 'min:0.01'],
            'sale_rate' => ['required', 'numeric', 'min:0'],
            'buyer_name' => ['nullable', 'string', 'max:255'],
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
            'buyer_name' => $validated['buyer_name'] ? trim($validated['buyer_name']) : null,
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

    public function markSalePaid(int $saleId): void
    {
        Gate::authorize('egg_sales.modify');

        EggSale::whereKey($saleId)->where('payment_status', 'due')->update(['payment_status' => 'paid']);

        session()->flash('status', 'Sale marked as paid.');
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
            EggSale::destroy($this->confirmingDeleteSaleId);
        }

        $this->confirmingDeleteSaleId = null;
        $this->dispatch('close-modal', 'confirm-egg-sale-deletion');
        session()->flash('status', 'Sale deleted.');
    }

    public function startCreateWaste(): void
    {
        $this->editingWasteId = null;
        $this->waste_date = now()->toDateString();
        $this->waste_quantity = null;
        $this->waste_remarks = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-waste-form');
    }

    public function startEditWaste(int $wasteId): void
    {
        $waste = EggWaste::findOrFail($wasteId);
        $this->editingWasteId = $waste->id;
        $this->waste_date = $waste->waste_date->format('Y-m-d');
        $this->waste_quantity = (string) $waste->quantity;
        $this->waste_remarks = $waste->remarks;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-waste-form');
    }

    public function saveWaste(): void
    {
        Gate::authorize($this->editingWasteId ? 'egg_purchases.modify' : 'egg_purchases.create');

        $validated = $this->validate([
            'waste_date' => ['required', 'date'],
            'waste_quantity' => ['required', 'numeric', 'min:0.01'],
            'waste_remarks' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'waste_quantity' => 'quantity',
        ]);

        $attributes = [
            'waste_date' => $validated['waste_date'],
            'quantity' => $validated['waste_quantity'],
            'remarks' => $validated['waste_remarks'] ? trim($validated['waste_remarks']) : null,
        ];

        if ($this->editingWasteId) {
            EggWaste::whereKey($this->editingWasteId)->update($attributes);
        } else {
            $attributes['created_by'] = auth()->id();
            EggWaste::create($attributes);
        }

        $this->dispatch('close-modal', 'egg-waste-form');
        session()->flash('status', $this->editingWasteId ? 'Waste updated.' : 'Waste recorded.');
    }

    public function confirmDeleteWaste(int $wasteId): void
    {
        $this->confirmingDeleteWasteId = $wasteId;
        $this->dispatch('open-modal', 'confirm-egg-waste-deletion');
    }

    public function deleteWaste(): void
    {
        Gate::authorize('egg_purchases.modify');

        if ($this->confirmingDeleteWasteId) {
            EggWaste::destroy($this->confirmingDeleteWasteId);
        }

        $this->confirmingDeleteWasteId = null;
        $this->dispatch('close-modal', 'confirm-egg-waste-deletion');
        session()->flash('status', 'Waste deleted.');
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
                ->when($this->rangeFilter, fn ($query) => $query->whereDate('purchase_date', '>=', now()->subDays((int) $this->rangeFilter - 1)->startOfDay()->toDateString()))
                ->when($this->yearFilter, fn ($query) => $query->whereYear('purchase_date', $this->yearFilter))
                ->when($this->monthFilter, fn ($query) => $query->whereMonth('purchase_date', $this->monthFilter))
                ->orderByDesc('purchase_date')
                ->orderByDesc('id')
                ->simplePaginate(10)
                ->setPath($this->paginationPath)
                ->appends($this->urlQueryState()),
            'availableYears' => $availableYears,
            'monthOptions' => $monthOptions,
            'costAmountPreview' => (is_numeric($this->quantity) && is_numeric($this->cost_rate))
                ? round((float) $this->quantity * (float) $this->cost_rate, 2)
                : null,
            'existingMemoUrl' => $this->existingMemoPath ? Storage::disk('public')->url($this->existingMemoPath) : null,
            'existingMemoIsPdf' => str_ends_with((string) $this->existingMemoPath, '.pdf'),
            ...$this->supplyReport(),
            ...$this->stockSummary(),
            ...$this->salesReport($monthOptions),
            ...$this->wasteReport($monthOptions),
        ];
    }

    /**
     * Eggs currently on hand: everything bought, minus everything Tiffin
     * has actually used (job_entries.quantity already includes the fixed
     * spoilage buffer on top of headcount — the real number of eggs sent
     * out, not just the billed headcount), minus everything sold to an
     * outside buyer, minus everything explicitly logged as wasted
     * (broken/spoiled beyond that fixed per-day buffer).
     *
     * @return array{eggTotalPurchased: float, eggTotalConsumed: float, eggTotalSold: float, eggTotalWasted: float, eggInStock: float, eggTotalRevenue: float}
     */
    private function stockSummary(): array
    {
        $eggItemId = TiffinItem::where('name', 'Egg')->value('id');

        $totalPurchased = $eggItemId
            ? (float) TiffinItemPurchase::where('tiffin_item_id', $eggItemId)->sum('quantity')
            : 0.0;

        $totalConsumed = (float) JobEntry::whereNotNull('tiffin_department_id')
            ->where('supply_type', 'Egg')
            ->sum('quantity');

        $totalSold = (float) EggSale::sum('quantity');
        $totalWasted = (float) EggWaste::sum('quantity');

        return [
            'eggTotalPurchased' => $totalPurchased,
            'eggTotalConsumed' => $totalConsumed,
            'eggTotalSold' => $totalSold,
            'eggTotalWasted' => $totalWasted,
            'eggInStock' => $totalPurchased - $totalConsumed - $totalSold - $totalWasted,
            'eggTotalRevenue' => (float) EggSale::sum('sale_amount'),
        ];
    }

    /**
     * @param  Collection<int, string>  $monthOptions
     * @return array{sales: LengthAwarePaginator, saleAvailableYears: Collection<int, int>, saleMonthOptions: Collection<int, string>, saleAmountPreview: ?float}
     */
    private function salesReport(Collection $monthOptions): array
    {
        $saleAvailableYears = EggSale::pluck('sale_date')
            ->map(fn ($date) => $date->year)
            ->unique()
            ->sortDesc()
            ->values();

        $sales = EggSale::when($this->saleRangeFilter, fn ($query) => $query->whereDate('sale_date', '>=', now()->subDays((int) $this->saleRangeFilter - 1)->startOfDay()->toDateString()))
            ->when($this->saleYearFilter, fn ($query) => $query->whereYear('sale_date', $this->saleYearFilter))
            ->when($this->saleMonthFilter, fn ($query) => $query->whereMonth('sale_date', $this->saleMonthFilter))
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
            'saleAmountPreview' => (is_numeric($this->sale_quantity) && is_numeric($this->sale_rate))
                ? round((float) $this->sale_quantity * (float) $this->sale_rate, 2)
                : null,
        ];
    }

    /**
     * @param  Collection<int, string>  $monthOptions
     * @return array{wastes: LengthAwarePaginator, wasteAvailableYears: Collection<int, int>, wasteMonthOptions: Collection<int, string>}
     */
    private function wasteReport(Collection $monthOptions): array
    {
        $wasteAvailableYears = EggWaste::pluck('waste_date')
            ->map(fn ($date) => $date->year)
            ->unique()
            ->sortDesc()
            ->values();

        $wastes = EggWaste::when($this->wasteRangeFilter, fn ($query) => $query->whereDate('waste_date', '>=', now()->subDays((int) $this->wasteRangeFilter - 1)->startOfDay()->toDateString()))
            ->when($this->wasteYearFilter, fn ($query) => $query->whereYear('waste_date', $this->wasteYearFilter))
            ->when($this->wasteMonthFilter, fn ($query) => $query->whereMonth('waste_date', $this->wasteMonthFilter))
            ->orderByDesc('waste_date')
            ->orderByDesc('id')
            ->simplePaginate(self::WASTE_PER_PAGE, ['*'], 'wastePage')
            ->setPath($this->paginationPath)
            ->appends($this->urlQueryState());

        return [
            'wastes' => $wastes,
            'wasteAvailableYears' => $wasteAvailableYears,
            'wasteMonthOptions' => $monthOptions,
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
            ->when($this->supplyRangeFilter, fn ($rows) => $rows->filter(fn (JobEntry $entry) => $entry->entry_date->greaterThanOrEqualTo(now()->subDays((int) $this->supplyRangeFilter - 1)->startOfDay())))
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

        $supplyDays = (new LengthAwarePaginator(
            $allDays->forPage($this->getPage('supplyPage'), self::SUPPLY_PER_PAGE)->values(),
            $allDays->count(),
            self::SUPPLY_PER_PAGE,
            $this->getPage('supplyPage'),
            ['pageName' => 'supplyPage', 'path' => $this->paginationPath]
        ))->appends($this->urlQueryState());

        $scopeLabel = match (true) {
            $this->supplyRangeFilter === '7' => 'Last 7 Days',
            $this->supplyRangeFilter === '15' => 'Last 15 Days',
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
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Egg Purchase & Stock Management</h2>
        @can('egg_purchases.create')
            @if ($activeView === 'purchases')
                <x-primary-button type="button" wire:click="startCreate">
                    + Record Purchase
                </x-primary-button>
            @endif
        @endcan
        @can('egg_sales.create')
            @if ($activeView === 'sales')
                <x-primary-button type="button" wire:click="startCreateSale">
                    + Record Sale
                </x-primary-button>
            @endif
        @endcan
        @can('egg_purchases.create')
            @if ($activeView === 'waste')
                <x-primary-button type="button" wire:click="startCreateWaste">
                    + Record Waste
                </x-primary-button>
            @endif
        @endcan
    </div>

    @can('egg_sales.view')
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">Purchased</p>
                <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ rtrim(rtrim(number_format($eggTotalPurchased, 2), '0'), '.') }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">Used by Tiffin</p>
                <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ rtrim(rtrim(number_format($eggTotalConsumed, 2), '0'), '.') }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">Sold Externally</p>
                <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ rtrim(rtrim(number_format($eggTotalSold, 2), '0'), '.') }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <p class="text-xs text-slate-500 dark:text-slate-400">Wasted</p>
                <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ rtrim(rtrim(number_format($eggTotalWasted, 2), '0'), '.') }}</p>
            </div>
            <div class="rounded-xl border border-brand-200 bg-brand-50 p-4 shadow-sm dark:border-brand-800 dark:bg-brand-900/20">
                <p class="text-xs text-brand-700 dark:text-brand-300">In Stock Now</p>
                <p class="mt-1 text-lg font-semibold text-brand-900 dark:text-white">{{ rtrim(rtrim(number_format($eggInStock, 2), '0'), '.') }}</p>
            </div>
        </div>
    @endcan

    <div class="inline-flex flex-wrap gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800/60">
        <button
            type="button"
            wire:click="switchView('purchases')"
            class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $activeView === 'purchases' ? 'bg-white text-brand-700 shadow-sm dark:bg-slate-700 dark:text-brand-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 9h14l-1.5 10.5a2 2 0 0 1-2 1.5H8.5a2 2 0 0 1-2-1.5L5 9z" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 9V6a3 3 0 0 1 6 0v3" /></svg>
            Purchases
        </button>
        <button
            type="button"
            wire:click="switchView('supply')"
            class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $activeView === 'supply' ? 'bg-white text-brand-700 shadow-sm dark:bg-slate-700 dark:text-brand-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3.5 12h17M3.5 12l4-4M3.5 12l4 4M20.5 12l-4-4M20.5 12l-4 4" /></svg>
            Supply by Item
        </button>
        @can('egg_sales.view')
            <button
                type="button"
                wire:click="switchView('sales')"
                class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $activeView === 'sales' ? 'bg-white text-brand-700 shadow-sm dark:bg-slate-700 dark:text-brand-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19V5m5 14V9m5 10V13m5 6V7" /></svg>
                Sales
            </button>
        @endcan
        <button
            type="button"
            wire:click="switchView('waste')"
            class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $activeView === 'waste' ? 'bg-white text-brand-700 shadow-sm dark:bg-slate-700 dark:text-brand-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M9.5 4h5l.5 3h-6l.5-3z" /></svg>
            Waste
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

            <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-800">
                @foreach (['' => 'All time', '7' => '7 Days', '15' => '15 Days', '30' => '30 Days'] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('rangeFilter', '{{ $value }}')"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ $rangeFilter === (string) $value ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

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
                        @if ($purchase->remarks)
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $purchase->remarks }}</p>
                        @endif
                        @if ($purchase->memo_url)
                            <a href="{{ $purchase->memo_url }}" target="_blank" rel="noopener" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h1M6 4h12a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z" /></svg>
                                {{ $purchase->memo_is_pdf ? 'View Memo (PDF)' : 'View Memo' }}
                            </a>
                        @endif
                    </div>
                    <span class="shrink-0 text-sm font-semibold text-slate-900 dark:text-white">{{ number_format((float) $purchase->cost_amount, 2) }}</span>
                </div>

                @can('egg_purchases.modify')
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
    @elseif ($activeView === 'supply')
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

            <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-800">
                @foreach (['' => 'All time', '7' => '7 Days', '15' => '15 Days', '30' => '30 Days'] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('supplyRangeFilter', '{{ $value }}')"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ $supplyRangeFilter === (string) $value ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

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
    @elseif ($activeView === 'sales')
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Eggs sold to an outside buyer, separate from Tiffin's own internal supply — each sale reduces
            the "In Stock Now" figure above just like Tiffin's daily use does.
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

            <x-text-input wire:model.live.debounce.400ms="saleInChargeFilter" placeholder="Search by In-Charge" class="w-full sm:w-48" />
        </div>

        @forelse ($sales as $sale)
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $sale->buyer_name ?: 'Egg Sale' }}</h3>
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
                        @if ($sale->payment_status === 'due')
                            <x-secondary-button type="button" wire:click="markSalePaid({{ $sale->id }})">
                                Mark Paid
                            </x-secondary-button>
                        @endif
                        <x-danger-button type="button" wire:click="confirmDeleteSale({{ $sale->id }})">
                            Delete
                        </x-danger-button>
                    </div>
                @endcan
            </div>
        @empty
            <x-empty-state
                :title="$saleAvailableYears->isNotEmpty() ? 'No sales match these filters' : 'No Egg sales recorded yet'"
                :message="$saleAvailableYears->isNotEmpty() ? 'Try a different year or month — or clear the filters above.' : 'Record a sale whenever eggs are sold to an outside buyer.'"
            />
        @endforelse

        {{ $sales->links('pagination::simple-tailwind') }}
    @elseif ($activeView === 'waste')
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Eggs broken or spoiled and thrown out — beyond the fixed daily buffer already built into Tiffin's
            own usage figure. Each entry reduces the "In Stock Now" figure above, same as a sale does.
        </p>

        <div class="flex flex-wrap items-center gap-3">
            <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-800">
                @foreach (['' => 'All time', '7' => '7 Days', '15' => '15 Days', '30' => '30 Days'] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('wasteRangeFilter', '{{ $value }}')"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ $wasteRangeFilter === (string) $value ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <x-select-input wire:model.live="wasteYearFilter" class="w-full sm:w-32">
                <option value="">Every year</option>
                @foreach ($wasteAvailableYears as $year)
                    <option value="{{ $year }}">{{ $year }}</option>
                @endforeach
            </x-select-input>

            <x-select-input wire:model.live="wasteMonthFilter" class="w-full sm:w-40" :disabled="! $wasteYearFilter">
                <option value="">{{ $wasteYearFilter ? 'Every month' : 'Pick a year first' }}</option>
                @foreach ($wasteMonthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select-input>
        </div>

        @forelse ($wastes as $waste)
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">Egg Waste</h3>
                            <span class="text-xs text-slate-400 dark:text-slate-500">{{ $waste->waste_date->format('d M Y') }}</span>
                        </div>
                        @if ($waste->remarks)
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $waste->remarks }}</p>
                        @endif
                    </div>
                    <span class="shrink-0 text-sm font-semibold text-slate-900 dark:text-white">{{ rtrim(rtrim(number_format((float) $waste->quantity, 2), '0'), '.') }}</span>
                </div>

                @can('egg_purchases.modify')
                    <div class="mt-4 flex items-center gap-3">
                        <x-secondary-button type="button" wire:click="startEditWaste({{ $waste->id }})">
                            Edit
                        </x-secondary-button>
                        <x-danger-button type="button" wire:click="confirmDeleteWaste({{ $waste->id }})">
                            Delete
                        </x-danger-button>
                    </div>
                @endcan
            </div>
        @empty
            <x-empty-state
                :title="$wasteAvailableYears->isNotEmpty() ? 'No waste matches these filters' : 'No Egg waste recorded yet'"
                :message="$wasteAvailableYears->isNotEmpty() ? 'Try a different year or month — or clear the filters above.' : 'Record it whenever eggs are broken or spoiled and thrown out.'"
            />
        @endforelse

        {{ $wastes->links('pagination::simple-tailwind') }}
    @endif

    <x-modal name="egg-purchase-form" focusable>
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
                <x-input-label for="purchase_memo" value="Purchase Memo" />

                @if ($existingMemoUrl)
                    <div class="mt-1 flex items-center gap-3">
                        @if ($existingMemoIsPdf)
                            <a href="{{ $existingMemoUrl }}" target="_blank" rel="noopener" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                                PDF
                            </a>
                        @else
                            <a href="{{ $existingMemoUrl }}" target="_blank" rel="noopener">
                                <img src="{{ $existingMemoUrl }}" alt="Purchase memo" class="h-12 w-12 shrink-0 rounded-lg border border-slate-200 object-cover dark:border-slate-700">
                            </a>
                        @endif
                        <a href="{{ $existingMemoUrl }}" target="_blank" rel="noopener" class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                            View current memo
                        </a>
                        <button type="button" wire:click="clearMemo" class="text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                            Remove
                        </button>
                    </div>
                @endif

                <input type="file" wire:model="memoFile" id="purchase_memo" accept="image/*,.pdf" class="mt-2 block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 dark:text-slate-400 dark:file:bg-slate-700 dark:file:text-slate-200">
                <div wire:loading wire:target="memoFile" class="mt-1 text-xs text-slate-400 dark:text-slate-500">Uploading…</div>
                @if ($memoFile && $memoFile->isPreviewable())
                    <img src="{{ $memoFile->temporaryUrl() }}" alt="Purchase memo preview" class="mt-2 max-h-24 rounded-lg border border-slate-200 dark:border-slate-700">
                @endif
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">JPG, PNG or PDF, up to 10MB.{{ $existingMemoUrl ? ' Choosing a new file replaces the one above.' : '' }}</p>
                <x-input-error :messages="$errors->get('memoFile')" class="mt-2" />
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

    <x-modal name="confirm-egg-purchase-deletion" focusable>
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

    <x-modal name="egg-sale-form" focusable>
        <form wire:submit="saveSale" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingSaleId ? 'Edit Sale' : 'Record Sale' }}
            </h2>

            <div>
                <x-input-label for="sale_date" value="Sale Date" />
                <x-text-input wire:model.live="sale_date" id="sale_date" type="date" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('sale_date')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="sale_quantity" value="Quantity" />
                <x-text-input wire:model.live.debounce.400ms="sale_quantity" id="sale_quantity" type="number" step="0.01" min="0" placeholder="e.g. 200" class="mt-1 block w-full" />
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
                <x-input-label for="buyer_name" value="Buyer Name" />
                <x-text-input wire:model="buyer_name" id="buyer_name" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('buyer_name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="payment_status" value="Payment Type" />
                <x-select-input wire:model="payment_status" id="payment_status" class="mt-1 block w-full">
                    <option value="cash">Cash</option>
                    <option value="due">Due</option>
                </x-select-input>
                <x-input-error :messages="$errors->get('payment_status')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="in_charge" value="In-Charge" />
                <x-text-input wire:model="in_charge" id="in_charge" placeholder="e.g. Mr. Karim" class="mt-1 block w-full" />
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
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                This cannot be undone. Deleting this sale increases the "In Stock Now" figure back up, since
                those eggs are no longer counted as sold.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="deleteSale">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="egg-waste-form" focusable>
        <form wire:submit="saveWaste" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingWasteId ? 'Edit Waste' : 'Record Waste' }}
            </h2>

            <div>
                <x-input-label for="waste_date" value="Date" />
                <x-text-input wire:model="waste_date" id="waste_date" type="date" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('waste_date')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="waste_quantity" value="Quantity" />
                <x-text-input wire:model="waste_quantity" id="waste_quantity" type="number" step="0.01" min="0" placeholder="e.g. 20" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('waste_quantity')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="waste_remarks" value="Remarks" />
                <x-textarea-input wire:model="waste_remarks" id="waste_remarks" placeholder="e.g. Crate dropped in storage" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('waste_remarks')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-primary-button>
                    {{ $editingWasteId ? 'Save Changes' : 'Record Waste' }}
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-egg-waste-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this waste record?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                This cannot be undone. Deleting it increases the "In Stock Now" figure back up, since those
                eggs are no longer counted as wasted.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="deleteWaste">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
