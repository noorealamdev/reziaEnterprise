<?php

use App\Models\Expense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
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

    public ?int $editingId = null;

    public string $expense_date = '';

    public ?string $amount = null;

    public string $description = '';

    public ?string $remarks = null;

    public ?int $confirmingDeleteId = null;

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

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->expense_date = now()->toDateString();
        $this->amount = null;
        $this->description = '';
        $this->remarks = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'expense-form');
    }

    public function startEdit(int $expenseId): void
    {
        $expense = Expense::findOrFail($expenseId);
        $this->editingId = $expense->id;
        $this->expense_date = $expense->expense_date->format('Y-m-d');
        $this->amount = (string) $expense->amount;
        $this->description = $expense->description;
        $this->remarks = $expense->remarks;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'expense-form');
    }

    public function save(): void
    {
        Gate::authorize($this->editingId ? 'expenses.modify' : 'expenses.create');

        $validated = $this->validate([
            'expense_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['description'] = trim($validated['description']);
        $validated['remarks'] = $validated['remarks'] ? trim($validated['remarks']) : null;

        if ($this->editingId) {
            Expense::whereKey($this->editingId)->update($validated);
        } else {
            $validated['created_by'] = auth()->id();
            Expense::create($validated);
        }

        $this->dispatch('close-modal', 'expense-form');
        session()->flash('status', $this->editingId ? 'Expense updated.' : 'Expense recorded.');
    }

    public function confirmDelete(int $expenseId): void
    {
        $this->confirmingDeleteId = $expenseId;
        $this->dispatch('open-modal', 'confirm-expense-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('expenses.modify');

        if ($this->confirmingDeleteId) {
            Expense::destroy($this->confirmingDeleteId);
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-expense-deletion');
        session()->flash('status', 'Expense deleted.');
    }

    public function with(): array
    {
        // Fetched as plain dates rather than a raw SQL YEAR() aggregate so
        // this stays portable between MySQL (prod) and SQLite (tests) —
        // same convention as Tiffin Purchases' own year filter.
        $availableYears = Expense::pluck('expense_date')
            ->map(fn ($date) => $date->year)
            ->unique()
            ->sortDesc()
            ->values();

        $monthOptions = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(2000, $m, 1)->format('F')]);

        $query = Expense::query()
            ->when($this->yearFilter, fn ($q) => $q->whereYear('expense_date', $this->yearFilter))
            ->when($this->monthFilter, fn ($q) => $q->whereMonth('expense_date', $this->monthFilter));

        // Cloned before pagination executes/mutates the query, so the total
        // reflects every filtered row, not just the current page.
        $filteredTotal = (float) (clone $query)->sum('amount');

        return [
            'expenses' => $query->orderByDesc('expense_date')->orderByDesc('id')->simplePaginate(10),
            'availableYears' => $availableYears,
            'monthOptions' => $monthOptions,
            'filteredTotal' => $filteredTotal,
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Expenses</h2>
        @can('expenses.create')
            <x-primary-button type="button" wire:click="startCreate">
                + Record Expense
            </x-primary-button>
        @endcan
    </div>

    <div class="flex flex-wrap items-center gap-3">
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
        <p class="text-xs text-slate-500 dark:text-slate-400">Total — {{ $yearFilter ? ($monthFilter ? $monthOptions[$monthFilter].' '.$yearFilter : $yearFilter) : 'all-time' }}</p>
        <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format($filteredTotal, 2) }}</p>
    </div>

    @forelse ($expenses as $expense)
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $expense->description }}</h3>
                        <span class="text-xs text-slate-400 dark:text-slate-500">{{ $expense->expense_date->format('d M Y') }}</span>
                    </div>
                    @if ($expense->remarks)
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $expense->remarks }}</p>
                    @endif
                </div>
                <span class="shrink-0 text-sm font-semibold text-slate-900 dark:text-white">{{ number_format((float) $expense->amount, 2) }}</span>
            </div>

            @can('expenses.modify')
                <div class="mt-4 flex items-center gap-3">
                    <x-secondary-button type="button" wire:click="startEdit({{ $expense->id }})">
                        Edit
                    </x-secondary-button>
                    <x-danger-button type="button" wire:click="confirmDelete({{ $expense->id }})">
                        Delete
                    </x-danger-button>
                </div>
            @endcan
        </div>
    @empty
        <x-empty-state
            :title="$availableYears->isNotEmpty() ? 'No expenses match these filters' : 'No expenses recorded yet'"
            :message="$availableYears->isNotEmpty() ? 'Try a different year or month — or clear the filters above.' : 'Record transport, tea bills, cash advances, or any other business expense here.'"
        >
            @can('expenses.create')
                <x-slot:action>
                    <x-primary-button type="button" wire:click="startCreate">
                        + Record Expense
                    </x-primary-button>
                </x-slot:action>
            @endcan
        </x-empty-state>
    @endforelse

    {{ $expenses->links('pagination::simple-tailwind') }}

    <x-modal name="expense-form" focusable>
        <form wire:submit="save" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingId ? 'Edit Expense' : 'Record Expense' }}
            </h2>

            <div>
                <x-input-label for="expense_date" value="Date" />
                <x-text-input wire:model="expense_date" id="expense_date" type="date" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('expense_date')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="amount" value="Amount" />
                <x-text-input wire:model="amount" id="amount" type="number" step="0.01" min="0.01" placeholder="e.g. 500.00" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('amount')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="description" value="Description" />
                <x-text-input wire:model="description" id="description" placeholder="e.g. Transport cost, Tea bill, Cash advance to Karim" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="remarks" value="Remarks" />
                <x-textarea-input wire:model="remarks" id="remarks" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('remarks')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-primary-button>
                    {{ $editingId ? 'Save Changes' : 'Record Expense' }}
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-expense-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this expense?</h2>
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
