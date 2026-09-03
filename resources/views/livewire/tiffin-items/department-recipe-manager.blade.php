<?php

use App\Models\JobEntry;
use App\Models\TiffinDepartment;
use App\Models\TiffinDepartmentItem;
use App\Models\TiffinItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $formDepartmentId = null;

    public ?int $tiffin_item_id = null;

    public ?int $removeBlockedRowId = null;

    public string $removeBlockedMessage = '';

    public ?int $confirmingRemoveRowId = null;

    #[On('tiffin-item-catalog-updated')]
    #[On('tiffin-department-updated')]
    public function refresh(): void
    {
        //
    }

    public function startAdd(int $departmentId): void
    {
        $this->formDepartmentId = $departmentId;
        $this->tiffin_item_id = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'recipe-item-form');
    }

    public function save(): void
    {
        Gate::authorize('service_categories.manage');

        $validated = $this->validate([
            'tiffin_item_id' => ['required', 'integer', 'exists:tiffin_items,id'],
        ]);

        $nextSortOrder = (int) TiffinDepartmentItem::where('tiffin_department_id', $this->formDepartmentId)->max('sort_order') + 1;

        TiffinDepartmentItem::create([
            'tiffin_department_id' => $this->formDepartmentId,
            'tiffin_item_id' => $validated['tiffin_item_id'],
            'sort_order' => $nextSortOrder,
        ]);

        $this->dispatch('close-modal', 'recipe-item-form');
        session()->flash('status', 'Item added to department.');
    }

    public function confirmRemove(int $rowId): void
    {
        $row = TiffinDepartmentItem::findOrFail($rowId);

        $hasJobEntries = JobEntry::where('tiffin_department_id', $row->tiffin_department_id)
            ->where('supply_type', $row->tiffinItem->name)
            ->exists();

        if ($hasJobEntries) {
            $this->removeBlockedMessage = "\"{$row->tiffinItem->name}\" already has job entries recorded for this department and can't be removed.";
            $this->dispatch('open-modal', 'recipe-remove-blocked');

            return;
        }

        $this->confirmingRemoveRowId = $rowId;
        $this->dispatch('open-modal', 'confirm-recipe-item-removal');
    }

    public function remove(): void
    {
        Gate::authorize('service_categories.manage');

        if ($this->confirmingRemoveRowId) {
            TiffinDepartmentItem::destroy($this->confirmingRemoveRowId);
        }

        $this->confirmingRemoveRowId = null;
        $this->dispatch('close-modal', 'confirm-recipe-item-removal');
        session()->flash('status', 'Item removed from department.');
    }

    public function moveUp(int $rowId): void
    {
        $this->swapOrder($rowId, 'up');
    }

    public function moveDown(int $rowId): void
    {
        $this->swapOrder($rowId, 'down');
    }

    private function swapOrder(int $rowId, string $direction): void
    {
        Gate::authorize('service_categories.manage');

        $row = TiffinDepartmentItem::findOrFail($rowId);

        $neighbor = TiffinDepartmentItem::where('tiffin_department_id', $row->tiffin_department_id)
            ->when(
                $direction === 'up',
                fn ($query) => $query->where('sort_order', '<', $row->sort_order)->orderByDesc('sort_order'),
                fn ($query) => $query->where('sort_order', '>', $row->sort_order)->orderBy('sort_order'),
            )
            ->first();

        if (! $neighbor) {
            return;
        }

        DB::transaction(function () use ($row, $neighbor) {
            [$rowOrder, $neighborOrder] = [$row->sort_order, $neighbor->sort_order];
            $row->update(['sort_order' => $neighborOrder]);
            $neighbor->update(['sort_order' => $rowOrder]);
        });
    }

    public function with(): array
    {
        $departments = TiffinDepartment::with('departmentItems.tiffinItem')
            ->orderBy('name')
            ->get();

        $catalogItems = TiffinItem::where('is_active', true)->orderBy('name')->get();

        $availableItemsByDepartment = [];

        foreach ($departments as $department) {
            $assignedItemIds = $department->departmentItems->pluck('tiffin_item_id');
            $availableItemsByDepartment[$department->id] = $catalogItems->whereNotIn('id', $assignedItemIds)->values();
        }

        return [
            'departments' => $departments,
            'availableItemsByDepartment' => $availableItemsByDepartment,
        ];
    }
}; ?>

<div class="space-y-6">
    <h2 class="text-base font-semibold text-slate-900 dark:text-white">Department Items</h2>
    <p class="-mt-4 text-sm text-slate-500 dark:text-slate-400">
        Choose which items belong to each department. Every Job Entry for that department will ask for a quantity and rate for each item listed here.
    </p>

    @forelse ($departments as $department)
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $department->name }}</h3>
                <x-secondary-button type="button" wire:click="startAdd({{ $department->id }})">
                    + Add Item
                </x-secondary-button>
            </div>

            @if ($department->departmentItems->isEmpty())
                <p class="mt-3 text-sm text-slate-400 dark:text-slate-500">No items yet.</p>
            @else
                <div class="mt-3 space-y-2">
                    @foreach ($department->departmentItems as $row)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-700 dark:bg-slate-900/50">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $row->tiffinItem->name }}</p>

                            <div class="flex shrink-0 items-center gap-1">
                                <button type="button" wire:click="moveUp({{ $row->id }})" class="rounded p-1 text-slate-400 hover:bg-slate-200 hover:text-slate-600 dark:hover:bg-slate-700 dark:hover:text-slate-300" title="Move up">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7" /></svg>
                                </button>
                                <button type="button" wire:click="moveDown({{ $row->id }})" class="rounded p-1 text-slate-400 hover:bg-slate-200 hover:text-slate-600 dark:hover:bg-slate-700 dark:hover:text-slate-300" title="Move down">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7" /></svg>
                                </button>
                                <x-danger-button type="button" wire:click="confirmRemove({{ $row->id }})" class="!px-2 !py-1 !text-xs">
                                    Remove
                                </x-danger-button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @empty
        <x-empty-state title="No departments yet" message="Add a Tiffin Department above before assigning items to it." />
    @endforelse

    <x-modal name="recipe-item-form" focusable>
        <form wire:submit="save" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                Add Item to Department
            </h2>

            <div>
                <x-input-label for="recipe_tiffin_item_id" value="Item" />
                <x-select-input wire:model="tiffin_item_id" id="recipe_tiffin_item_id" class="mt-1 block w-full" required>
                    <option value="">Select an item…</option>
                    @foreach (($availableItemsByDepartment[$formDepartmentId] ?? []) as $catalogItem)
                        <option value="{{ $catalogItem->id }}">{{ $catalogItem->name }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('tiffin_item_id')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-primary-button>
                    Add Item
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-recipe-item-removal" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Remove this item from the department?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">This cannot be undone.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="remove">Remove</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="recipe-remove-blocked" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Can't remove this item</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $removeBlockedMessage }}</p>
            <div class="mt-6 flex justify-end">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Close</x-secondary-button>
            </div>
        </div>
    </x-modal>
</div>
