<?php

use App\Models\Company;
use App\Models\InCharge;
use App\Models\JobEntry;
use App\Models\ServiceCategory;
use App\Models\TiffinDepartmentItem;
use App\Models\TiffinItem;
use App\Models\TiffinItemPurchase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public ?JobEntry $jobEntry = null;

    public ?int $company_id = null;

    public ?int $service_category_id = null;

    public ?int $tiffin_department_id = null;

    public string $inChargeSelection = '';

    public bool $addingInCharge = false;

    public string $newInChargeName = '';

    public ?string $newInChargePhone = null;

    public string $entry_date = '';

    public string $supply_type = '';

    public ?string $buyer = null;

    public ?string $style = null;

    public ?string $floor = null;

    public ?string $challan_no = null;

    public ?string $company_adv_payment = null;

    public ?string $quantity = null;

    public ?string $cost_rate = null;

    public ?string $bill_rate = null;

    public ?string $cost_amount = null;

    public ?string $bill_amount = null;

    public bool $is_off_day = false;

    public ?string $remarks = null;

    /** @var array<string, int> */
    public array $categoryIds = [];

    public bool $multiItemMode = false;

    /** @var array<int, array<string, string>> tiffin_department_id => item name => value */
    public array $batchQuantities = [];

    /** @var array<int, array<string, string>> tiffin_department_id => item name => value */
    public array $batchCostRates = [];

    /** @var array<int, array<string, string>> tiffin_department_id => item name => value */
    public array $batchBillRates = [];

    /** @var array<int, string> tiffin_department_id => exchange item name (optional, covers a Banana shortfall) */
    public array $batchExchangeItemNames = [];

    /** @var array<int, string> tiffin_department_id => exchange item quantity */
    public array $batchExchangeQuantities = [];

    /** @var array<int, string> tiffin_department_id => exchange item cost rate */
    public array $batchExchangeCostRates = [];

    public function mount(?JobEntry $jobEntry = null): void
    {
        $this->jobEntry = ($jobEntry && $jobEntry->exists) ? $jobEntry : null;

        if ($this->jobEntry && $this->jobEntry->isBilled) {
            session()->flash('error', 'This entry has already been billed and can\'t be edited.');
            $this->redirect(route('job-entries.index'), navigate: true);

            return;
        }

        $this->categoryIds = ServiceCategory::pluck('id', 'name')->all();

        if ($this->jobEntry) {
            $entry = $this->jobEntry;
            $this->company_id = $entry->company_id;
            $this->service_category_id = $entry->service_category_id;
            $this->tiffin_department_id = $entry->tiffin_department_id;
            $this->inChargeSelection = $entry->in_charge_id ? (string) $entry->in_charge_id : '';
            $this->entry_date = $entry->entry_date->format('Y-m-d');
            $this->supply_type = $entry->supply_type;
            $this->buyer = $entry->buyer;
            $this->style = $entry->style;
            $this->floor = $entry->floor;
            $this->challan_no = $entry->challan_no;
            $this->company_adv_payment = $entry->company_adv_payment !== null ? (string) $entry->company_adv_payment : null;
            $this->quantity = $entry->quantity !== null ? (string) $entry->quantity : null;
            $this->cost_rate = $entry->cost_rate !== null ? (string) $entry->cost_rate : null;
            $this->bill_rate = $entry->bill_rate !== null ? (string) $entry->bill_rate : null;
            $this->cost_amount = (string) $entry->cost_amount;
            $this->bill_amount = (string) $entry->bill_amount;
            $this->is_off_day = $entry->is_off_day;
            $this->remarks = $entry->remarks;
        } else {
            $this->entry_date = now()->toDateString();
            $this->cost_amount = '';
            $this->bill_amount = '';
            $this->company_id = request()->integer('company') ?: null;
        }
    }

    public function updatedCompanyId(): void
    {
        $this->service_category_id = null;
        $this->tiffin_department_id = null;
        $this->supply_type = '';
        $this->multiItemMode = false;
        $this->batchQuantities = [];
        $this->batchCostRates = [];
        $this->batchBillRates = [];
        $this->batchExchangeItemNames = [];
        $this->batchExchangeQuantities = [];
        $this->batchExchangeCostRates = [];
    }

    public function updatedServiceCategoryId(): void
    {
        $tiffinId = $this->categoryIds['Tiffin'] ?? null;

        if ($this->service_category_id != $tiffinId) {
            $this->tiffin_department_id = null;
        }

        $this->supply_type = '';
        $this->buyer = null;
        $this->style = null;
        $this->floor = null;
        $this->challan_no = null;
        $this->company_adv_payment = null;
        $this->refreshMultiItemMode();
        $this->attemptRateAutoFill();
    }

    public function updatedTiffinDepartmentId(): void
    {
        $this->supply_type = '';
        $this->refreshMultiItemMode();
        $this->attemptRateAutoFill();
    }

    public function updatedSupplyType(): void
    {
        $this->attemptRateAutoFill();
    }

    public function updatedBuyer(): void
    {
        $this->attemptRateAutoFill();
    }

    public function updatedQuantity(): void
    {
        $this->recomputeAmounts();
    }

    public function updatedCostRate(): void
    {
        $this->recomputeAmounts();
    }

    public function updatedBillRate(): void
    {
        $this->recomputeAmounts();
    }

    public function updatedInChargeSelection(): void
    {
        $this->addingInCharge = $this->inChargeSelection === 'new';
    }

    /**
     * A day's purchase (e.g. today's Egg) can only be looked up once the
     * date is known — the cost-rate lock needs re-checking whenever it
     * changes, without wiping whatever quantities/rates are already typed
     * in (that's what refreshMultiItemMode() is for, on a company/
     * category/department change). Typing an exchange item's name also
     * suggests a cost rate from that name's own history, same idea as
     * Banana/Bread's own auto-fill.
     */
    public function updated(string $name): void
    {
        if ($name === 'entry_date') {
            $this->applyPurchaseLocks();

            return;
        }

        if (str_starts_with($name, 'batchExchangeItemNames.')) {
            [, $departmentId] = explode('.', $name, 2);
            $this->refreshExchangeItemRate((int) $departmentId);
        }
    }

    /**
     * Force today's Tiffin cost rates to match any matching purchase
     * record — the whole point of locking is that it can't be typed over,
     * so this always wins over whatever was there before. Only touches
     * cost rate, and only when a purchase actually matches, so a date
     * change never wipes quantities/rates that were already typed in.
     */
    private function applyPurchaseLocks(): void
    {
        foreach ($this->tiffinDepartmentsWithItems() as $department) {
            foreach ($department->items as $item) {
                $purchase = TiffinItemPurchase::findFor($item->name, $this->entry_date);

                if ($purchase) {
                    $this->batchCostRates[$department->id][$item->name] = (string) $purchase->cost_rate;
                }
            }
        }
    }

    /**
     * Suggests a cost rate for the exchange item from that name's own most
     * recent entry, the same way every other item's cost rate auto-fills —
     * an exchange item builds its own independent rate history over
     * repeated use, since it's just an ordinary supply_type once saved.
     */
    private function refreshExchangeItemRate(int $departmentId): void
    {
        $name = trim((string) ($this->batchExchangeItemNames[$departmentId] ?? ''));

        if ($name === '') {
            $this->batchExchangeCostRates[$departmentId] = '';

            return;
        }

        $recent = $this->mostRecentJobEntry($name, $departmentId);
        $this->batchExchangeCostRates[$departmentId] = $recent ? (string) $recent->cost_rate : '';
    }

    /**
     * Every Tiffin department this company is assigned, each with its
     * configured recipe items — or an empty collection if a multi-department
     * batch entry doesn't apply (not Tiffin, no company chosen, or editing
     * an existing single entry, which uses the legacy single-department
     * fields below instead). A department with no recipe items configured
     * is left out, since there's nothing to fill in for it.
     *
     * @return Collection<int, object{id: int, name: string, items: Collection}>
     */
    private function tiffinDepartmentsWithItems(): Collection
    {
        $tiffinId = $this->categoryIds['Tiffin'] ?? null;

        if ($this->jobEntry || $tiffinId === null || $this->service_category_id != $tiffinId || ! $this->company_id) {
            return collect();
        }

        $company = Company::find($this->company_id);

        if (! $company) {
            return collect();
        }

        return $company->tiffinDepartments()->orderBy('name')->get()
            ->map(fn ($department) => (object) [
                'id' => $department->id,
                'name' => $department->name,
                'items' => TiffinDepartmentItem::with('tiffinItem')
                    ->where('tiffin_department_id', $department->id)
                    ->whereHas('tiffinItem', fn ($query) => $query->where('is_active', true))
                    ->orderBy('sort_order')
                    ->get()
                    ->pluck('tiffinItem'),
            ])
            ->filter(fn ($department) => $department->items->isNotEmpty())
            ->values();
    }

    /**
     * Which department absorbs the Egg buffer — the client sends +5 extra
     * eggs once for the whole company's delivery that day, not once per
     * department, so only the first department (in the given order) with
     * an actual Egg quantity typed carries it; every other department's
     * Egg row stores exactly its headcount.
     *
     * @param  Collection<int, object{id: int, items: Collection}>  $departments
     */
    private function firstEggDepartmentId(Collection $departments): ?int
    {
        return $departments->first(function ($department) {
            if (! $department->items->contains(fn ($item) => $item->name === 'Egg')) {
                return false;
            }

            return is_numeric($this->batchQuantities[$department->id]['Egg'] ?? null);
        })?->id;
    }

    private function refreshMultiItemMode(): void
    {
        $departments = $this->tiffinDepartmentsWithItems();
        $this->multiItemMode = $departments->isNotEmpty();

        $this->batchQuantities = [];
        $this->batchCostRates = [];
        $this->batchBillRates = [];
        $this->batchExchangeItemNames = [];
        $this->batchExchangeQuantities = [];
        $this->batchExchangeCostRates = [];

        $company = $this->company_id ? Company::find($this->company_id) : null;

        foreach ($departments as $department) {
            foreach ($department->items as $item) {
                $recent = $this->mostRecentJobEntry($item->name, $department->id);

                $this->batchQuantities[$department->id][$item->name] = '';
                $this->batchCostRates[$department->id][$item->name] = $recent ? (string) $recent->cost_rate : '';

                // Only Egg bills — the whole Tiffin meal is a fixed rate per
                // person (Egg's quantity), not a rate per ingredient.
                // Banana/Bread never get a batchBillRates entry at all.
                if ($item->name === 'Egg') {
                    $this->batchBillRates[$department->id][$item->name] = $company?->tiffin_bill_rate !== null
                        ? (string) $company->tiffin_bill_rate
                        : ($recent ? (string) $recent->bill_rate : '');
                }
            }

            $this->batchExchangeItemNames[$department->id] = '';
            $this->batchExchangeQuantities[$department->id] = '';
            $this->batchExchangeCostRates[$department->id] = '';
        }

        $this->applyPurchaseLocks();
    }

    /**
     * The most recent Job Entry matching the current company/category/
     * department/buyer context for a given supply type — the sole source of
     * "what rate did we last use", now that rates live on entries themselves
     * rather than a separate rate table.
     */
    private function mostRecentJobEntry(string $supplyType, ?int $tiffinDepartmentId = null): ?JobEntry
    {
        return JobEntry::where('company_id', $this->company_id)
            ->where('service_category_id', $this->service_category_id)
            ->where('supply_type', $supplyType)
            ->where('tiffin_department_id', $tiffinDepartmentId)
            ->where('buyer', $this->buyer)
            ->when($this->jobEntry, fn ($query) => $query->whereKeyNot($this->jobEntry->id))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->first();
    }

    public function attemptRateAutoFill(): void
    {
        if (! $this->company_id || ! $this->service_category_id || trim((string) $this->supply_type) === '') {
            return;
        }

        $recent = $this->mostRecentJobEntry(trim($this->supply_type), $this->tiffin_department_id);

        if ($recent) {
            $this->cost_rate = (string) $recent->cost_rate;
            $this->bill_rate = (string) $recent->bill_rate;
            $this->recomputeAmounts();
        }
    }

    private function recomputeAmounts(): void
    {
        if (is_numeric($this->quantity) && is_numeric($this->cost_rate)) {
            $this->cost_amount = (string) round((float) $this->quantity * (float) $this->cost_rate, 2);
        }

        if (is_numeric($this->quantity) && is_numeric($this->bill_rate)) {
            $this->bill_amount = (string) round((float) $this->quantity * (float) $this->bill_rate, 2);
        }
    }

    private function resolveInChargeId(): ?int
    {
        if ($this->inChargeSelection === 'new') {
            return InCharge::create([
                'name' => trim($this->newInChargeName),
                'phone' => $this->newInChargePhone ? trim($this->newInChargePhone) : null,
                'is_active' => true,
            ])->id;
        }

        return $this->inChargeSelection !== '' ? (int) $this->inChargeSelection : null;
    }

    public function save(): void
    {
        Gate::authorize($this->jobEntry ? 'job_entries.modify' : 'job_entries.create');

        if ($this->multiItemMode) {
            return;
        }

        $tiffinId = $this->categoryIds['Tiffin'] ?? null;
        $embroideryId = $this->categoryIds['Embroidery & Print'] ?? null;
        $loadingUnloadingId = $this->categoryIds['Loading Unloading'] ?? null;
        $dieselId = $this->categoryIds['Diesel Oil Supply'] ?? null;
        $etpEidId = $this->categoryIds['ETP Eid Holiday'] ?? null;

        $validated = $this->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'service_category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'tiffin_department_id' => [
                Rule::requiredIf(fn () => $this->service_category_id == $tiffinId),
                'nullable', 'integer', 'exists:tiffin_departments,id',
            ],
            'entry_date' => ['required', 'date'],
            'supply_type' => ['required', 'string', 'max:255'],
            'buyer' => [
                Rule::requiredIf(fn () => $this->service_category_id == $embroideryId),
                'nullable', 'string', 'max:255',
            ],
            'style' => [
                Rule::requiredIf(fn () => $this->service_category_id == $embroideryId),
                'nullable', 'string', 'max:255',
            ],
            'floor' => [
                Rule::requiredIf(fn () => $this->service_category_id == $loadingUnloadingId),
                'nullable', 'string', 'max:255',
            ],
            'challan_no' => ['nullable', 'string', 'max:255'],
            'company_adv_payment' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'cost_rate' => ['nullable', 'numeric', 'min:0'],
            'bill_rate' => ['nullable', 'numeric', 'min:0'],
            'cost_amount' => ['required', 'numeric', 'min:0'],
            'bill_amount' => ['required', 'numeric', 'min:0'],
            'is_off_day' => ['boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'newInChargeName' => [
                Rule::requiredIf(fn () => $this->inChargeSelection === 'new'),
                'nullable', 'string', 'max:255',
            ],
            'newInChargePhone' => ['nullable', 'string', 'max:30'],
        ]);

        if ($validated['service_category_id'] != $tiffinId) {
            $validated['tiffin_department_id'] = null;
        }

        if ($validated['service_category_id'] != $embroideryId) {
            $validated['buyer'] = null;
            $validated['style'] = null;
        }

        if ($validated['service_category_id'] != $loadingUnloadingId) {
            $validated['floor'] = null;
        }

        if ($validated['service_category_id'] != $dieselId) {
            $validated['challan_no'] = null;
        }

        if ($validated['service_category_id'] != $etpEidId) {
            $validated['company_adv_payment'] = null;
        }

        $validated['supply_type'] = trim($validated['supply_type']);
        $validated['buyer'] = $validated['buyer'] ? trim($validated['buyer']) : null;

        // Defense in depth for the legacy single-entry Tiffin edit path: a
        // purchase-locked item's cost rate must win here too, not just in
        // the batch form, even though this path is only reachable by
        // editing a pre-batch-era entry directly.
        if ($validated['tiffin_department_id'] && $validated['quantity'] !== null) {
            $purchase = TiffinItemPurchase::findFor($validated['supply_type'], $validated['entry_date']);

            if ($purchase) {
                $validated['cost_rate'] = (float) $purchase->cost_rate;
                $validated['cost_amount'] = round((float) $validated['quantity'] * (float) $purchase->cost_rate, 2);
            }
        }

        unset($validated['newInChargeName'], $validated['newInChargePhone']);

        DB::transaction(function () use ($validated) {
            $validated['in_charge_id'] = $this->resolveInChargeId();

            $jobEntry = $this->jobEntry ?? new JobEntry;
            $jobEntry->fill($validated);
            $jobEntry->save();
        });

        session()->flash('status', $this->jobEntry ? 'Job entry updated.' : 'Job entry created.');

        $this->redirect(route('job-entries.index'), navigate: true);
    }

    public function saveTiffinItemBatch(): void
    {
        Gate::authorize('job_entries.create');

        $departments = $this->tiffinDepartmentsWithItems();

        if ($departments->isEmpty()) {
            return;
        }

        // A department only needs filling in if the user actually started
        // typing into it — that's how the same form covers "just Swing
        // today" and "both Swing and Wash Worker today" without forcing
        // every department to be filled every time. Once a department is
        // touched, all of its items become required, same strictness as
        // before. An exchange item name alone also counts as touching it,
        // so a department can't be silently skipped just because its
        // quantity fields happen to still be blank.
        $touchedDepartments = $departments->filter(function ($department) {
            $touchedByQuantity = collect($this->batchQuantities[$department->id] ?? [])
                ->contains(fn ($value) => trim((string) $value) !== '');
            $touchedByExchange = trim((string) ($this->batchExchangeItemNames[$department->id] ?? '')) !== '';

            return $touchedByQuantity || $touchedByExchange;
        })->values();

        if ($touchedDepartments->isEmpty()) {
            $this->addError('batchQuantities', 'Enter quantities for at least one department.');

            return;
        }

        $rules = [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'entry_date' => ['required', 'date'],
            'newInChargeName' => [
                Rule::requiredIf(fn () => $this->inChargeSelection === 'new'),
                'nullable', 'string', 'max:255',
            ],
        ];

        foreach ($touchedDepartments as $department) {
            foreach ($department->items as $item) {
                $rules["batchQuantities.{$department->id}.{$item->name}"] = ['required', 'numeric', 'min:0'];
                $rules["batchCostRates.{$department->id}.{$item->name}"] = ['required', 'numeric', 'min:0'];

                // Only Egg bills — the whole meal is one fixed rate per person.
                if ($item->name === 'Egg') {
                    $rules["batchBillRates.{$department->id}.Egg"] = ['required', 'numeric', 'min:0'];
                }
            }

            $hasExchangeName = trim((string) ($this->batchExchangeItemNames[$department->id] ?? '')) !== '';
            $rules["batchExchangeItemNames.{$department->id}"] = ['nullable', 'string', 'max:255'];
            $rules["batchExchangeQuantities.{$department->id}"] = [Rule::requiredIf($hasExchangeName), 'nullable', 'numeric', 'min:0'];
            $rules["batchExchangeCostRates.{$department->id}"] = [Rule::requiredIf($hasExchangeName), 'nullable', 'numeric', 'min:0'];
        }

        $this->validate($rules);

        // An exchange item named the same as a catalog item in the same
        // department/day would collide once saved (the batch-edit screen
        // keys rows by item name) — reject before writing anything.
        foreach ($touchedDepartments as $department) {
            $exchangeName = trim((string) ($this->batchExchangeItemNames[$department->id] ?? ''));

            if ($exchangeName === '') {
                continue;
            }

            $collides = $department->items->contains(fn ($item) => strcasecmp(trim($item->name), $exchangeName) === 0);

            if ($collides) {
                $this->addError("batchExchangeItemNames.{$department->id}", "\"{$exchangeName}\" is already one of {$department->name}'s items — use a different name.");

                return;
            }
        }

        $rows = [];

        // The +5 buffer is sent once for the whole company's delivery that
        // day, not once per department — so only the first department
        // (alphabetically, same ordering used everywhere else) that
        // actually has an Egg quantity typed absorbs it.
        $eggBufferDepartmentId = $this->firstEggDepartmentId($departments);

        foreach ($touchedDepartments as $department) {
            foreach ($department->items as $item) {
                $isEgg = $item->name === 'Egg';

                // For Egg, what's typed is the day's headcount, not the
                // literal egg count — the client always sends a fixed
                // buffer of extra eggs beyond headcount (spoilage/
                // breakage margin). That buffer is a real cost (bought
                // and paid for) but never billed, since the factory pays
                // per person actually served.
                $headcountOrQty = (float) $this->batchQuantities[$department->id][$item->name];
                $appliesEggBuffer = $isEgg && $department->id === $eggBufferDepartmentId;
                $storedQuantity = $appliesEggBuffer ? $headcountOrQty + config('tiffin.egg_buffer_quantity') : $headcountOrQty;

                // A disabled input isn't a security boundary — when a
                // purchase exists for this item/date, its cost rate wins
                // regardless of what was posted for the field.
                $purchase = TiffinItemPurchase::findFor($item->name, $this->entry_date);
                $costRate = $purchase ? (float) $purchase->cost_rate : (float) $this->batchCostRates[$department->id][$item->name];

                // Only Egg's row carries a bill — Banana/Bread (and any
                // exchange item) are cost-tracking only.
                $billRate = $isEgg ? (float) $this->batchBillRates[$department->id]['Egg'] : 0.0;

                $rows[] = [
                    'tiffin_department_id' => $department->id,
                    'name' => $item->name,
                    'quantity' => $storedQuantity,
                    'cost_rate' => $costRate,
                    'bill_rate' => $billRate,
                    'cost_amount' => round($storedQuantity * $costRate, 2),
                    // Bill is always headcount × rate, never the buffered
                    // egg count × rate.
                    'bill_amount' => round($headcountOrQty * $billRate, 2),
                ];
            }

            $exchangeName = trim((string) ($this->batchExchangeItemNames[$department->id] ?? ''));

            if ($exchangeName !== '') {
                $exchangeQty = (float) $this->batchExchangeQuantities[$department->id];
                $exchangeCostRate = (float) $this->batchExchangeCostRates[$department->id];

                $rows[] = [
                    'tiffin_department_id' => $department->id,
                    'name' => $exchangeName,
                    'quantity' => $exchangeQty,
                    'cost_rate' => $exchangeCostRate,
                    'bill_rate' => 0.0,
                    'cost_amount' => round($exchangeQty * $exchangeCostRate, 2),
                    'bill_amount' => 0.0,
                ];
            }
        }

        DB::transaction(function () use ($rows) {
            $inChargeId = $this->resolveInChargeId();

            foreach ($rows as $row) {
                JobEntry::create([
                    'company_id' => $this->company_id,
                    'service_category_id' => $this->service_category_id,
                    'tiffin_department_id' => $row['tiffin_department_id'],
                    'in_charge_id' => $inChargeId,
                    'entry_date' => $this->entry_date,
                    'supply_type' => $row['name'],
                    'quantity' => $row['quantity'],
                    'cost_rate' => $row['cost_rate'],
                    'bill_rate' => $row['bill_rate'],
                    'cost_amount' => $row['cost_amount'],
                    'bill_amount' => $row['bill_amount'],
                    'is_off_day' => $this->is_off_day,
                    'remarks' => $this->remarks,
                ]);
            }
        });

        session()->flash('status', 'Tiffin entries created ('.count($rows).').');

        $this->redirect(route('job-entries.index'), navigate: true);
    }

    public function with(): array
    {
        $company = $this->company_id ? Company::find($this->company_id) : null;
        $tiffinId = $this->categoryIds['Tiffin'] ?? null;

        // Edit-only: item options for the legacy single-department fields
        // (only reachable when fixing an old Tiffin entry that predates the
        // per-department batch flow, or otherwise has no department set).
        $tiffinItemOptions = ($this->jobEntry && $this->tiffin_department_id)
            ? TiffinDepartmentItem::with('tiffinItem')
                ->where('tiffin_department_id', $this->tiffin_department_id)
                ->whereHas('tiffinItem', fn ($query) => $query->where('is_active', true))
                ->orderBy('sort_order')
                ->get()
                ->pluck('tiffinItem.name')
            : collect();

        $eggBuffer = config('tiffin.egg_buffer_quantity');
        $allTiffinDepartments = $this->tiffinDepartmentsWithItems();
        $eggBufferDepartmentId = $this->firstEggDepartmentId($allTiffinDepartments);
        $eggBufferDepartmentName = $allTiffinDepartments->firstWhere('id', $eggBufferDepartmentId)?->name;

        $tiffinDepartmentSections = $allTiffinDepartments->map(function ($department) use ($eggBuffer, $eggBufferDepartmentId, $eggBufferDepartmentName) {
            $items = $department->items->map(function ($item) use ($department, $eggBuffer, $eggBufferDepartmentId, $eggBufferDepartmentName) {
                $qty = $this->batchQuantities[$department->id][$item->name] ?? '';
                $costRate = $this->batchCostRates[$department->id][$item->name] ?? '';
                $isEgg = $item->name === 'Egg';
                $billRate = $isEgg ? ($this->batchBillRates[$department->id]['Egg'] ?? '') : null;
                $purchase = $this->entry_date ? TiffinItemPurchase::findFor($item->name, $this->entry_date) : null;
                $appliesEggBuffer = $isEgg && $department->id === $eggBufferDepartmentId;

                // For Egg, "quantity" typed is the headcount — the actual
                // egg count costed is headcount + the fixed buffer, but
                // only for the one department carrying it (see
                // firstEggDepartmentId()).
                $costQty = ($appliesEggBuffer && is_numeric($qty)) ? (float) $qty + $eggBuffer : $qty;

                return (object) [
                    'name' => $item->name,
                    'bills' => $isEgg,
                    'isEgg' => $isEgg,
                    'actualEggQuantity' => ($appliesEggBuffer && is_numeric($qty)) ? (float) $qty + $eggBuffer : null,
                    'bufferAppliesElsewhere' => ($isEgg && ! $appliesEggBuffer && $eggBufferDepartmentName) ? $eggBufferDepartmentName : null,
                    'costAmount' => (is_numeric($qty) && is_numeric($costRate)) ? round((float) $costQty * (float) $costRate, 2) : null,
                    'billAmount' => ($isEgg && is_numeric($qty) && is_numeric($billRate)) ? round((float) $qty * (float) $billRate, 2) : null,
                    'locked' => $purchase !== null,
                    'purchase' => $purchase,
                ];
            });

            $exchangeName = $this->batchExchangeItemNames[$department->id] ?? '';
            $exchangeQty = $this->batchExchangeQuantities[$department->id] ?? '';
            $exchangeCostRate = $this->batchExchangeCostRates[$department->id] ?? '';

            return (object) [
                'id' => $department->id,
                'name' => $department->name,
                'items' => $items,
                'exchangeCostAmount' => (is_numeric($exchangeQty) && is_numeric($exchangeCostRate))
                    ? round((float) $exchangeQty * (float) $exchangeCostRate, 2)
                    : null,
            ];
        });

        // Create + Tiffin selected, but the company has no department with
        // a configured recipe — nothing to fill in and no plain-text
        // fallback for Tiffin, so the form has to block submission here
        // and point at where to fix it.
        $tiffinBlockedNoDepartments = ! $this->jobEntry
            && $tiffinId !== null
            && $this->service_category_id == $tiffinId
            && $tiffinDepartmentSections->isEmpty();

        return [
            'companies' => Company::orderBy('name')->get(),
            'serviceCategories' => $company ? $company->serviceCategories()->orderBy('sort_order')->get() : collect(),
            'tiffinDepartments' => $company ? $company->tiffinDepartments()->orderBy('name')->get() : collect(),
            'inCharges' => InCharge::where('is_active', true)->orderBy('name')->get(),
            'supplyTypeSuggestions' => ($this->company_id && $this->service_category_id)
                ? JobEntry::where('company_id', $this->company_id)
                    ->where('service_category_id', $this->service_category_id)
                    ->distinct()
                    ->orderBy('supply_type')
                    ->pluck('supply_type')
                : collect(),
            'tiffinItemOptions' => $tiffinItemOptions,
            'tiffinDepartmentSections' => $tiffinDepartmentSections,
            'tiffinBlockedNoDepartments' => $tiffinBlockedNoDepartments,
            'eggBuffer' => $eggBuffer,
        ];
    }
}; ?>

<div class="space-y-6">
    <form wire:submit="save" class="space-y-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-800">
        <div>
            <x-input-label for="company_id" value="Company" />
            <x-select-input wire:model.live="company_id" id="company_id" class="mt-1 block w-full" required>
                <option value="">Select a company…</option>
                @foreach ($companies as $company)
                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                @endforeach
            </x-select-input>
            <x-input-error :messages="$errors->get('company_id')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="service_category_id" value="Service Category" />
            <x-select-input wire:model.live="service_category_id" id="service_category_id" class="mt-1 block w-full" required :disabled="! $company_id">
                <option value="">Select a category…</option>
                @foreach ($serviceCategories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </x-select-input>
            <x-input-error :messages="$errors->get('service_category_id')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="entry_date" value="Entry Date" />
            <x-text-input wire:model.live="entry_date" id="entry_date" type="date" class="mt-1 block w-full" required />
            <x-input-error :messages="$errors->get('entry_date')" class="mt-2" />
        </div>

        @if ($jobEntry)
            {{-- Legacy single-entry Tiffin editing — normal Tiffin entries are
            edited as a whole department batch instead (see Job Entries list),
            this only covers an old entry with no department set. --}}
            <div x-show="$wire.service_category_id == {{ $categoryIds['Tiffin'] ?? 0 }}" x-cloak>
                <x-input-label for="tiffin_department_id" value="Tiffin Department" />
                <x-select-input wire:model.live="tiffin_department_id" id="tiffin_department_id" class="mt-1 block w-full">
                    <option value="">Select a department…</option>
                    @foreach ($tiffinDepartments as $department)
                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('tiffin_department_id')" class="mt-2" />
            </div>

            <div x-show="$wire.service_category_id == {{ $categoryIds['Tiffin'] ?? 0 }} && ! $wire.tiffin_department_id" x-cloak>
                <p class="text-sm text-slate-500 dark:text-slate-400">Select a Tiffin Department to see its items.</p>
            </div>

            <div x-show="$wire.service_category_id == {{ $categoryIds['Tiffin'] ?? 0 }} && $wire.tiffin_department_id" x-cloak>
                <x-input-label for="supply_type_select" value="Tiffin Item" />
                <x-select-input wire:model.live="supply_type" id="supply_type_select" class="mt-1 block w-full">
                    <option value="">Select an item…</option>
                    @foreach ($tiffinItemOptions as $itemName)
                        <option value="{{ $itemName }}">{{ $itemName }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('supply_type')" class="mt-2" />
            </div>
        @endif

        <div x-show="$wire.service_category_id != {{ $categoryIds['Tiffin'] ?? 0 }}">
            <x-input-label for="supply_type_text" value="Supply Type" />
            <x-text-input wire:model.live.debounce.500ms="supply_type" id="supply_type_text" list="supply-type-suggestions" placeholder="e.g. Local Sand Supply, Daily Basic Labour" class="mt-1 block w-full" required />
            <datalist id="supply-type-suggestions">
                @foreach ($supplyTypeSuggestions as $suggestion)
                    <option value="{{ $suggestion }}"></option>
                @endforeach
            </datalist>
            <x-input-error :messages="$errors->get('supply_type')" class="mt-2" />
        </div>

        @if (! $jobEntry)
            {{-- Create + Tiffin: every department the company subscribes to
            gets its own section, so a day with tiffin for both Swing and
            Wash Worker is one submission, not two. A department left
            entirely blank is simply skipped. --}}
            <div x-show="$wire.service_category_id == {{ $categoryIds['Tiffin'] ?? 0 }}" x-cloak class="space-y-4">
                @if ($tiffinBlockedNoDepartments)
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        This company has no Tiffin department with items configured yet. Set one up in
                        <a href="{{ route('service-categories.index') }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">Service Categories → Manage Items</a>.
                    </p>
                @elseif ($tiffinDepartmentSections->isNotEmpty())
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Fill in whichever departments had tiffin today — leave any department blank to skip it. Rates are pre-filled from the last time each item was entered.
                    </p>

                    @foreach ($tiffinDepartmentSections as $department)
                        <div class="rounded-lg border border-brand-200 bg-brand-50 p-4 dark:border-brand-800 dark:bg-brand-900/20">
                            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $department->name }}</h3>

                            <div class="mt-3 space-y-3">
                                @foreach ($department->items as $item)
                                    <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-800">
                                        <div class="flex items-center gap-2">
                                            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $item->name }}</p>
                                            @if ($item->bills)
                                                <x-badge color="brand">Bills</x-badge>
                                            @endif
                                        </div>

                                        <div class="mt-2 grid {{ $item->bills ? 'grid-cols-3' : 'grid-cols-2' }} gap-2">
                                            <div>
                                                <x-input-label :for="'batch_qty_'.$department->id.'_'.$item->name" :value="$item->isEgg ? 'Headcount' : 'Quantity'" class="!mb-0 text-xs" />
                                                <x-text-input
                                                    wire:model.live.debounce.400ms="batchQuantities.{{ $department->id }}.{{ $item->name }}"
                                                    :id="'batch_qty_'.$department->id.'_'.$item->name"
                                                    type="number" step="0.01" min="0" :placeholder="$item->isEgg ? 'e.g. 2500' : 'e.g. 200'"
                                                    class="mt-1 block w-full text-sm"
                                                />
                                                <x-input-error :messages="$errors->get('batchQuantities.'.$department->id.'.'.$item->name)" class="mt-1" />
                                                @if ($item->actualEggQuantity !== null)
                                                    <p class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">
                                                        +{{ rtrim(rtrim(number_format($eggBuffer, 2), '0'), '.') }} buffer (once for the whole company) → {{ rtrim(rtrim(number_format($item->actualEggQuantity, 2), '0'), '.') }} eggs costed
                                                    </p>
                                                @elseif ($item->bufferAppliesElsewhere)
                                                    <p class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">
                                                        Buffer already added under {{ $item->bufferAppliesElsewhere }} for the day
                                                    </p>
                                                @endif
                                            </div>
                                            <div>
                                                <x-input-label :for="'batch_cost_'.$department->id.'_'.$item->name" value="Cost Rate" class="!mb-0 text-xs" />
                                                <x-text-input
                                                    wire:model.live.debounce.400ms="batchCostRates.{{ $department->id }}.{{ $item->name }}"
                                                    :id="'batch_cost_'.$department->id.'_'.$item->name"
                                                    type="number" step="0.01" min="0" placeholder="e.g. 11.50"
                                                    class="mt-1 block w-full text-sm"
                                                    :disabled="$item->locked"
                                                />
                                                <x-input-error :messages="$errors->get('batchCostRates.'.$department->id.'.'.$item->name)" class="mt-1" />
                                            </div>
                                            @if ($item->bills)
                                                <div>
                                                    <x-input-label for="batch_bill_egg" value="Bill Rate" class="!mb-0 text-xs" />
                                                    <x-text-input
                                                        wire:model.live.debounce.400ms="batchBillRates.{{ $department->id }}.Egg"
                                                        id="batch_bill_egg"
                                                        type="number" step="0.01" min="0" placeholder="e.g. 30.00"
                                                        class="mt-1 block w-full text-sm"
                                                    />
                                                    <x-input-error :messages="$errors->get('batchBillRates.'.$department->id.'.Egg')" class="mt-1" />
                                                </div>
                                            @endif
                                        </div>

                                        @if ($item->locked)
                                            <p class="mt-2 text-xs text-brand-700 dark:text-brand-300">
                                                Locked from the
                                                {{ $item->purchase->purchase_date->isSameDay($entry_date) ? "day's" : $item->purchase->purchase_date->format('d M Y')."'s" }}
                                                purchase: {{ rtrim(rtrim(number_format((float) $item->purchase->quantity, 2), '0'), '.') }} @ {{ number_format((float) $item->purchase->cost_rate, 2) }}
                                                @if ($item->purchase->supplier_name)
                                                    from {{ $item->purchase->supplier_name }}
                                                @endif
                                                — <a href="{{ route('tiffin-purchases.index') }}" wire:navigate class="font-medium underline">View Tiffin Purchases</a>
                                            </p>
                                        @endif

                                        @if ($item->bills && $item->costAmount !== null && $item->billAmount !== null)
                                            <div class="mt-2 grid grid-cols-3 gap-2 text-center text-xs">
                                                <div>
                                                    <p class="text-slate-400">Cost</p>
                                                    <p class="font-semibold text-slate-700 dark:text-slate-300">{{ number_format($item->costAmount, 2) }}</p>
                                                </div>
                                                <div>
                                                    <p class="text-slate-400">Bill</p>
                                                    <p class="font-semibold text-slate-700 dark:text-slate-300">{{ number_format($item->billAmount, 2) }}</p>
                                                </div>
                                                <div>
                                                    <p class="text-slate-400">Profit</p>
                                                    <p class="font-semibold text-slate-700 dark:text-slate-300">{{ number_format($item->billAmount - $item->costAmount, 2) }}</p>
                                                </div>
                                            </div>
                                        @elseif ($item->costAmount !== null)
                                            <p class="mt-2 text-center text-xs text-slate-500 dark:text-slate-400">
                                                Cost <span class="font-semibold text-slate-700 dark:text-slate-300">{{ number_format($item->costAmount, 2) }}</span>
                                            </p>
                                        @endif
                                    </div>
                                @endforeach

                                {{-- Exchange item: covers a Banana shortfall (e.g. 1500 of 2500 were
                                real Banana, the rest a substitute) as its own tracked, cost-only row —
                                never billed, since the factory pays per person regardless of the mix. --}}
                                <div class="rounded-lg border border-dashed border-slate-300 bg-white p-3 dark:border-slate-600 dark:bg-slate-800">
                                    <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Exchange Item <span class="font-normal text-slate-400">(optional — covers a Banana shortfall)</span></p>

                                    <div class="mt-2">
                                        <x-input-label :for="'batch_exchange_name_'.$department->id" value="Item Name" class="!mb-0 text-xs" />
                                        <x-text-input
                                            wire:model.live.debounce.500ms="batchExchangeItemNames.{{ $department->id }}"
                                            :id="'batch_exchange_name_'.$department->id"
                                            placeholder="e.g. Biscuit"
                                            class="mt-1 block w-full text-sm"
                                        />
                                        <x-input-error :messages="$errors->get('batchExchangeItemNames.'.$department->id)" class="mt-1" />
                                    </div>

                                    <div class="mt-2 grid grid-cols-2 gap-2">
                                        <div>
                                            <x-input-label :for="'batch_exchange_qty_'.$department->id" value="Quantity" class="!mb-0 text-xs" />
                                            <x-text-input
                                                wire:model.live.debounce.400ms="batchExchangeQuantities.{{ $department->id }}"
                                                :id="'batch_exchange_qty_'.$department->id"
                                                type="number" step="0.01" min="0" placeholder="e.g. 1000"
                                                class="mt-1 block w-full text-sm"
                                            />
                                            <x-input-error :messages="$errors->get('batchExchangeQuantities.'.$department->id)" class="mt-1" />
                                        </div>
                                        <div>
                                            <x-input-label :for="'batch_exchange_cost_'.$department->id" value="Cost Rate" class="!mb-0 text-xs" />
                                            <x-text-input
                                                wire:model.live.debounce.400ms="batchExchangeCostRates.{{ $department->id }}"
                                                :id="'batch_exchange_cost_'.$department->id"
                                                type="number" step="0.01" min="0" placeholder="e.g. 8.00"
                                                class="mt-1 block w-full text-sm"
                                            />
                                            <x-input-error :messages="$errors->get('batchExchangeCostRates.'.$department->id)" class="mt-1" />
                                        </div>
                                    </div>

                                    @if ($department->exchangeCostAmount !== null)
                                        <p class="mt-2 text-center text-xs text-slate-500 dark:text-slate-400">
                                            Cost <span class="font-semibold text-slate-700 dark:text-slate-300">{{ number_format($department->exchangeCostAmount, 2) }}</span>
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <x-input-error :messages="$errors->get('batchQuantities')" class="mt-2" />
                @endif
            </div>
        @endif

        <div x-show="$wire.service_category_id == {{ $categoryIds['Embroidery & Print'] ?? 0 }}" x-cloak class="space-y-6">
            <div>
                <x-input-label for="buyer" value="Buyer" />
                <x-text-input wire:model.live.debounce.500ms="buyer" id="buyer" placeholder="e.g. American Eagle, GAP" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('buyer')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="style" value="Style" />
                <x-text-input wire:model="style" id="style" placeholder="e.g. 6856" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('style')" class="mt-2" />
            </div>
        </div>

        <div x-show="$wire.service_category_id == {{ $categoryIds['Loading Unloading'] ?? 0 }}" x-cloak>
            <x-input-label for="floor" value="Floor" />
            <x-text-input wire:model="floor" id="floor" placeholder="e.g. Mazzanine Floor" class="mt-1 block w-full" />
            <x-input-error :messages="$errors->get('floor')" class="mt-2" />
        </div>

        <div x-show="$wire.service_category_id == {{ $categoryIds['Diesel Oil Supply'] ?? 0 }}" x-cloak>
            <x-input-label for="challan_no" value="Challan No." />
            <x-text-input wire:model="challan_no" id="challan_no" placeholder="e.g. 598" class="mt-1 block w-full" />
            <x-input-error :messages="$errors->get('challan_no')" class="mt-2" />
        </div>

        <div x-show="$wire.service_category_id == {{ $categoryIds['ETP Eid Holiday'] ?? 0 }}" x-cloak>
            <x-input-label for="company_adv_payment" value="Company Advance Payment" />
            <x-text-input wire:model="company_adv_payment" id="company_adv_payment" type="number" step="0.01" min="0" placeholder="e.g. 100000.00" class="mt-1 block w-full" />
            <x-input-error :messages="$errors->get('company_adv_payment')" class="mt-2" />
        </div>

        @unless ($tiffinBlockedNoDepartments)
        <div x-show="! $wire.multiItemMode" class="space-y-6">
            <div>
                <x-input-label for="quantity" value="Quantity" />
                <x-text-input wire:model.live.debounce.400ms="quantity" id="quantity" type="number" step="0.01" min="0" placeholder="e.g. 5" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('quantity')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="cost_rate" value="Cost Rate" />
                <x-text-input wire:model.live.debounce.400ms="cost_rate" id="cost_rate" type="number" step="0.01" min="0" placeholder="e.g. 11.50 (what we pay)" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('cost_rate')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="bill_rate" value="Bill Rate" />
                <x-text-input wire:model.live.debounce.400ms="bill_rate" id="bill_rate" type="number" step="0.01" min="0" placeholder="e.g. 30.00 (what we charge)" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('bill_rate')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="cost_amount" value="Cost Amount" />
                <x-text-input wire:model="cost_amount" id="cost_amount" type="number" step="0.01" min="0" placeholder="Auto-filled from Quantity × Cost Rate" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('cost_amount')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="bill_amount" value="Bill Amount" />
                <x-text-input wire:model="bill_amount" id="bill_amount" type="number" step="0.01" min="0" placeholder="Auto-filled from Quantity × Bill Rate" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('bill_amount')" class="mt-2" />
            </div>
        </div>
        @endunless

        <div class="flex items-center gap-2">
            <input type="checkbox" wire:model="is_off_day" id="is_off_day" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            <x-input-label for="is_off_day" value="Off Day (no billing)" class="!mb-0" />
        </div>

        <div>
            <x-input-label for="inChargeSelection" value="In-Charge" />
            <x-select-input wire:model.live="inChargeSelection" id="inChargeSelection" class="mt-1 block w-full">
                <option value="">None</option>
                @foreach ($inCharges as $inCharge)
                    <option value="{{ $inCharge->id }}">{{ $inCharge->name }}</option>
                @endforeach
                <option value="new">+ Add new in-charge…</option>
            </x-select-input>
        </div>

        <div x-show="$wire.inChargeSelection === 'new'" x-cloak class="space-y-6">
            <div>
                <x-input-label for="newInChargeName" value="New In-Charge Name" />
                <x-text-input wire:model="newInChargeName" id="newInChargeName" placeholder="e.g. Mr. Monir" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('newInChargeName')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="newInChargePhone" value="New In-Charge Phone" />
                <x-text-input wire:model="newInChargePhone" id="newInChargePhone" placeholder="e.g. 01712345678" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('newInChargePhone')" class="mt-2" />
            </div>
        </div>

        <div>
            <x-input-label for="remarks" value="Remarks" />
            <x-textarea-input wire:model="remarks" id="remarks" placeholder="e.g. Off day due to public holiday" class="mt-1 block w-full" />
            <x-input-error :messages="$errors->get('remarks')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end gap-3">
            <x-secondary-button :href="route('job-entries.index')" wire:navigate>
                Cancel
            </x-secondary-button>

            <button
                type="button"
                x-show="$wire.multiItemMode"
                x-cloak
                wire:click="saveTiffinItemBatch"
                class="inline-flex items-center px-4 py-2 bg-slate-800 dark:bg-slate-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-slate-800 uppercase tracking-widest hover:bg-slate-700 dark:hover:bg-white focus:bg-slate-700 dark:focus:bg-white active:bg-slate-900 dark:active:bg-slate-300 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800 transition ease-in-out duration-150"
            >
                Save Tiffin Entries
            </button>

            <x-primary-button x-show="! $wire.multiItemMode && {{ $tiffinBlockedNoDepartments ? 'false' : 'true' }}">
                {{ $jobEntry ? 'Save Changes' : 'Create Entry' }}
            </x-primary-button>
        </div>
    </form>
</div>
