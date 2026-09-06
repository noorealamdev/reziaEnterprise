<?php

use App\Models\LoadingUnloadingItem;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public ?string $unit_label = null;

    public bool $is_active = true;

    public ?int $confirmingDeleteId = null;

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->unit_label = null;
        $this->is_active = true;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'loading-unloading-item-form');
    }

    public function startEdit(int $itemId): void
    {
        $item = LoadingUnloadingItem::findOrFail($itemId);
        $this->editingId = $item->id;
        $this->name = $item->name;
        $this->unit_label = $item->unit_label;
        $this->is_active = $item->is_active;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'loading-unloading-item-form');
    }

    public function save(): void
    {
        Gate::authorize('service_categories.manage');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('loading_unloading_items', 'name')->ignore($this->editingId)],
            'unit_label' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        $validated['name'] = trim($validated['name']);
        $validated['unit_label'] = $validated['unit_label'] ? trim($validated['unit_label']) : null;

        if ($this->editingId) {
            LoadingUnloadingItem::whereKey($this->editingId)->update($validated);
        } else {
            $validated['sort_order'] = ((int) LoadingUnloadingItem::max('sort_order')) + 1;
            LoadingUnloadingItem::create($validated);
        }

        $this->dispatch('close-modal', 'loading-unloading-item-form');
        session()->flash('status', $this->editingId ? 'Item updated.' : 'Item added.');
    }

    public function confirmDelete(int $itemId): void
    {
        $this->confirmingDeleteId = $itemId;
        $this->dispatch('open-modal', 'confirm-loading-unloading-item-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('service_categories.manage');

        if ($this->confirmingDeleteId) {
            LoadingUnloadingItem::destroy($this->confirmingDeleteId);
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-loading-unloading-item-deletion');
        session()->flash('status', 'Item deleted.');
    }

    public function with(): array
    {
        return [
            'items' => LoadingUnloadingItem::orderBy('sort_order')->orderBy('name')->get(),
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Loading Unloading Item Catalog</h2>
        <x-primary-button type="button" wire:click="startCreate">
            + Add Item
        </x-primary-button>
    </div>

    <p class="text-xs text-slate-500 dark:text-slate-400">
        Each item shows up as its own row in the Job Entry batch form for Loading Unloading, with its own
        unit of measure — e.g. "Big" is billed per Cover Van, "Daily Labour" per Person.
    </p>

    @forelse ($items as $item)
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $item->name }}</h3>
                        <x-badge color="{{ $item->is_active ? 'green' : 'slate' }}">
                            {{ $item->is_active ? 'Active' : 'Inactive' }}
                        </x-badge>
                    </div>
                    @if ($item->unit_label)
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Unit: {{ $item->unit_label }}</p>
                    @endif
                </div>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <x-secondary-button type="button" wire:click="startEdit({{ $item->id }})">
                    Edit
                </x-secondary-button>
                <x-danger-button type="button" wire:click="confirmDelete({{ $item->id }})">
                    Delete
                </x-danger-button>
            </div>
        </div>
    @empty
        <x-empty-state title="No Loading Unloading items yet" message="Add items like Big, Small or Daily Labour to build the batch entry form." />
    @endforelse

    <x-modal name="loading-unloading-item-form" focusable>
        <form wire:submit="save" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingId ? 'Edit Item' : 'Add Item' }}
            </h2>

            <div>
                <x-input-label for="lu_item_name" value="Name" />
                <x-text-input wire:model="name" id="lu_item_name" placeholder="e.g. Big" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="lu_item_unit_label" value="Unit Label" />
                <x-text-input wire:model="unit_label" id="lu_item_unit_label" placeholder="e.g. Cover Van, Set, Person" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('unit_label')" class="mt-2" />
            </div>

            <div class="flex items-center gap-2">
                <input type="checkbox" wire:model="is_active" id="lu_item_is_active" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                <x-input-label for="lu_item_is_active" value="Active" class="!mb-0" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-primary-button>
                    {{ $editingId ? 'Save Changes' : 'Add Item' }}
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-loading-unloading-item-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this item?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                This cannot be undone. Job entries already saved with this item keep their own values —
                deleting it only affects new batch entries going forward.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="delete">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
