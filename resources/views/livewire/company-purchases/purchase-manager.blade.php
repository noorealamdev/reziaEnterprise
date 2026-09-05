<?php

use App\Models\Company;
use App\Models\CompanyPurchase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'company', history: true)]
    public string $companyFilter = '';

    #[Url(as: 'year', history: true)]
    public string $yearFilter = '';

    #[Url(as: 'month', history: true)]
    public string $monthFilter = '';

    public ?int $editingId = null;

    public ?int $company_id = null;

    public string $purchase_date = '';

    public ?string $description = null;

    public ?string $bill_number = null;

    public ?string $quantity = null;

    public ?string $rate = null;

    public ?string $amount = null;

    public ?string $remarks = null;

    public ?string $existingMemoPath = null;

    public $memoFile = null;

    public bool $removeMemo = false;

    public ?int $confirmingDeleteId = null;

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

    public function updatingCompanyFilter(): void
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

    /**
     * @return array<string, string>
     */
    private function urlQueryState(): array
    {
        return array_filter([
            'company' => $this->companyFilter,
            'year' => $this->yearFilter,
            'month' => $this->monthFilter,
        ], fn ($value) => $value !== '');
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->company_id = $this->companyFilter ? (int) $this->companyFilter : null;
        $this->purchase_date = now()->toDateString();
        $this->description = null;
        $this->bill_number = null;
        $this->quantity = null;
        $this->rate = null;
        $this->amount = null;
        $this->remarks = null;
        $this->existingMemoPath = null;
        $this->memoFile = null;
        $this->removeMemo = false;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'company-purchase-form');
    }

    public function startEdit(int $purchaseId): void
    {
        $purchase = CompanyPurchase::findOrFail($purchaseId);
        $this->editingId = $purchase->id;
        $this->company_id = $purchase->company_id;
        $this->purchase_date = $purchase->purchase_date->format('Y-m-d');
        $this->description = $purchase->description;
        $this->bill_number = $purchase->bill_number;
        $this->quantity = $purchase->quantity !== null ? (string) $purchase->quantity : null;
        $this->rate = $purchase->rate !== null ? (string) $purchase->rate : null;
        $this->amount = (string) $purchase->amount;
        $this->remarks = $purchase->remarks;
        $this->existingMemoPath = $purchase->memo_path;
        $this->memoFile = null;
        $this->removeMemo = false;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'company-purchase-form');
    }

    public function clearMemo(): void
    {
        $this->existingMemoPath = null;
        $this->removeMemo = true;
    }

    /**
     * Quantity × Rate auto-fills Amount when both are numeric, but Amount
     * stays directly editable — some goods purchases are a lump sum with
     * no clean per-unit breakdown (e.g. an assorted lot), unlike Egg's
     * purchase form where quantity and rate are always required.
     */
    public function updated(string $name): void
    {
        if (in_array($name, ['quantity', 'rate'], true) && is_numeric($this->quantity) && is_numeric($this->rate)) {
            $this->amount = (string) round((float) $this->quantity * (float) $this->rate, 2);
        }
    }

    public function save(): void
    {
        Gate::authorize($this->editingId ? 'company_purchases.modify' : 'company_purchases.create');

        $validated = $this->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'purchase_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'bill_number' => ['nullable', 'string', 'max:100'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'memoFile' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['description'] = trim($validated['description']);
        $validated['bill_number'] = $validated['bill_number'] ? trim($validated['bill_number']) : null;
        $validated['remarks'] = $validated['remarks'] ? trim($validated['remarks']) : null;

        $existingPurchase = $this->editingId ? CompanyPurchase::find($this->editingId) : null;

        unset($validated['memoFile']);

        if ($this->memoFile) {
            if ($existingPurchase?->memo_path) {
                Storage::disk('public')->delete($existingPurchase->memo_path);
            }
            $validated['memo_path'] = $this->memoFile->store('company-purchase-memos', 'public');
        } elseif ($this->removeMemo) {
            if ($existingPurchase?->memo_path) {
                Storage::disk('public')->delete($existingPurchase->memo_path);
            }
            $validated['memo_path'] = null;
        }

        if ($this->editingId) {
            CompanyPurchase::whereKey($this->editingId)->update($validated);
        } else {
            $validated['created_by'] = auth()->id();
            CompanyPurchase::create($validated);
        }

        $this->memoFile = null;
        $this->removeMemo = false;
        $this->dispatch('close-modal', 'company-purchase-form');
        session()->flash('status', $this->editingId ? 'Purchase updated.' : 'Purchase recorded.');
    }

    public function confirmDelete(int $purchaseId): void
    {
        $this->confirmingDeleteId = $purchaseId;
        $this->dispatch('open-modal', 'confirm-company-purchase-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('company_purchases.modify');

        if ($this->confirmingDeleteId) {
            $purchase = CompanyPurchase::find($this->confirmingDeleteId);

            if ($purchase?->memo_path) {
                Storage::disk('public')->delete($purchase->memo_path);
            }

            $purchase?->delete();
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-company-purchase-deletion');
        session()->flash('status', 'Purchase deleted.');
    }

    public function with(): array
    {
        $purchasesQuery = CompanyPurchase::with('company')
            ->when($this->companyFilter, fn ($query) => $query->where('company_id', $this->companyFilter))
            ->when($this->yearFilter, fn ($query) => $query->whereYear('purchase_date', $this->yearFilter))
            ->when($this->monthFilter, fn ($query) => $query->whereMonth('purchase_date', $this->monthFilter));

        // Fetched as plain dates rather than a raw SQL YEAR() aggregate so
        // this stays portable between MySQL (prod) and SQLite (tests).
        $availableYears = CompanyPurchase::when($this->companyFilter, fn ($query) => $query->where('company_id', $this->companyFilter))
            ->pluck('purchase_date')
            ->map(fn ($date) => $date->year)
            ->unique()
            ->sortDesc()
            ->values();

        $monthOptions = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(2000, $m, 1)->format('F')]);

        return [
            'companies' => Company::orderBy('name')->get(),
            'purchases' => $purchasesQuery->clone()
                ->orderByDesc('purchase_date')
                ->orderByDesc('id')
                ->simplePaginate(10)
                ->setPath($this->paginationPath)
                ->appends($this->urlQueryState()),
            // Always the full filtered set's total, not just the current
            // page — a paginated slice must never understate this figure.
            'totalPurchased' => (float) $purchasesQuery->clone()->sum('amount'),
            'availableYears' => $availableYears,
            'monthOptions' => $monthOptions,
            'existingMemoUrl' => $this->existingMemoPath ? Storage::disk('public')->url($this->existingMemoPath) : null,
            'existingMemoIsPdf' => str_ends_with((string) $this->existingMemoPath, '.pdf'),
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Company Purchases</h2>
        @can('company_purchases.create')
            <x-primary-button type="button" wire:click="startCreate">
                + Record Purchase
            </x-primary-button>
        @endcan
    </div>

    <p class="text-xs text-slate-500 dark:text-slate-400">
        Goods Rezia Enterprise buys from a company — tracked independently from what that company owes on
        its own invoices, never netted against it.
    </p>

    <div class="flex flex-wrap items-center gap-3">
        <x-select-input wire:model.live="companyFilter" class="w-full sm:w-56">
            <option value="">All companies</option>
            @foreach ($companies as $company)
                <option value="{{ $company->id }}">{{ $company->name }}</option>
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

    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
        <p class="text-xs text-slate-500 dark:text-slate-400">Total Purchased{{ $companyFilter ? '' : ' — All Companies' }}</p>
        <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format($totalPurchased, 2) }}</p>
    </div>

    @forelse ($purchases as $purchase)
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $purchase->description }}</h3>
                        <span class="text-xs text-slate-400 dark:text-slate-500">{{ $purchase->purchase_date->format('d M Y') }}</span>
                        @if ($purchase->bill_number)
                            <x-badge color="slate">Bill #{{ $purchase->bill_number }}</x-badge>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ $purchase->company->name }}
                        @if ($purchase->quantity !== null && $purchase->rate !== null)
                            · Qty {{ rtrim(rtrim(number_format((float) $purchase->quantity, 2), '0'), '.') }}
                            · Rate {{ number_format((float) $purchase->rate, 2) }}
                        @endif
                    </p>
                    @if ($purchase->remainingBalance != $purchase->amount)
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Remaining: <span class="font-medium text-slate-700 dark:text-slate-300">{{ number_format($purchase->remainingBalance, 2) }}</span> of {{ number_format((float) $purchase->amount, 2) }}
                        </p>
                    @endif
                    @if ($purchase->memo_url)
                        <a href="{{ $purchase->memo_url }}" target="_blank" rel="noopener" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h1M6 4h12a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z" /></svg>
                            {{ $purchase->memo_is_pdf ? 'View Memo (PDF)' : 'View Memo' }}
                        </a>
                    @endif
                </div>
                <span class="shrink-0 text-sm font-semibold text-slate-900 dark:text-white">{{ number_format((float) $purchase->amount, 2) }}</span>
            </div>

            @can('company_purchases.modify')
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
            :message="$availableYears->isNotEmpty() ? 'Try a different company, year or month — or clear the filters above.' : 'Record a purchase whenever Rezia Enterprise buys goods from a company.'"
        />
    @endforelse

    {{ $purchases->links('pagination::simple-tailwind') }}

    <x-modal name="company-purchase-form" focusable>
        <form wire:submit="save" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingId ? 'Edit Purchase' : 'Record Purchase' }}
            </h2>

            <div>
                <x-input-label for="purchase_company" value="Company" />
                <x-select-input wire:model.live="company_id" id="purchase_company" class="mt-1 block w-full" required>
                    <option value="">Select a company…</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('company_id')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="purchase_description" value="What was purchased" />
                <x-text-input wire:model="description" id="purchase_description" placeholder="e.g. Fabric Rolls, Garments Lot #12" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="purchase_bill_number" value="Bill Number" />
                <x-text-input wire:model="bill_number" id="purchase_bill_number" placeholder="Optional — the bill/invoice number the seller issued" class="mt-1 block w-full" />
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Used later to identify this bill when adjusting an invoice against it.</p>
                <x-input-error :messages="$errors->get('bill_number')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="purchase_date" value="Purchase Date" />
                <x-text-input wire:model.live="purchase_date" id="purchase_date" type="date" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('purchase_date')" class="mt-2" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="purchase_quantity" value="Quantity (optional)" />
                    <x-text-input wire:model.live.debounce.400ms="quantity" id="purchase_quantity" type="number" step="0.01" min="0" placeholder="e.g. 50" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('quantity')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="purchase_rate" value="Rate (optional)" />
                    <x-text-input wire:model.live.debounce.400ms="rate" id="purchase_rate" type="number" step="0.01" min="0" placeholder="e.g. 500" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('rate')" class="mt-2" />
                </div>
            </div>

            <div>
                <x-input-label for="purchase_amount" value="Amount" />
                <x-text-input wire:model="amount" id="purchase_amount" type="number" step="0.01" min="0" placeholder="e.g. 25000.00" class="mt-1 block w-full" />
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Filled in automatically from Quantity × Rate when both are set — otherwise enter it directly.</p>
                <x-input-error :messages="$errors->get('amount')" class="mt-2" />
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

    <x-modal name="confirm-company-purchase-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this purchase?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                This cannot be undone.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="delete">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
