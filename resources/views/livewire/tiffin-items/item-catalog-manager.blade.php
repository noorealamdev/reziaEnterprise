<?php

use App\Models\TiffinItem;
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

    public string $deleteBlockedMessage = '';

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->unit_label = null;
        $this->is_active = true;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'tiffin-item-form');
    }

    public function startEdit(int $itemId): void
    {
        $item = TiffinItem::findOrFail($itemId);
        $this->editingId = $item->id;
        $this->name = $item->name;
        $this->unit_label = $item->unit_label;
        $this->is_active = $item->is_active;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'tiffin-item-form');
    }

    public function save(): void
    {
        Gate::authorize('service_categories.manage');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('tiffin_items', 'name')->ignore($this->editingId)],
            'unit_label' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        $validated['name'] = trim($validated['name']);
        $validated['unit_label'] = $validated['unit_label'] ? trim($validated['unit_label']) : null;

        if ($this->editingId) {
            TiffinItem::whereKey($this->editingId)->update($validated);
        } else {
            TiffinItem::create($validated);
        }

        $this->dispatch('close-modal', 'tiffin-item-form');
        $this->dispatch('tiffin-item-catalog-updated');
        session()->flash('status', $this->editingId ? 'Tiffin item updated.' : 'Tiffin item added.');
    }

    public function confirmDelete(int $itemId): void
    {
        $item = TiffinItem::withCount('departmentItems')->findOrFail($itemId);

        if ($item->department_items_count > 0) {
            $this->deleteBlockedMessage = "\"{$item->name}\" is still assigned to a department recipe and can't be deleted. Remove it from the recipe first.";
            $this->dispatch('open-modal', 'tiffin-item-delete-blocked');

            return;
        }

        $this->confirmingDeleteId = $itemId;
        $this->dispatch('open-modal', 'confirm-tiffin-item-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('service_categories.manage');

        if ($this->confirmingDeleteId) {
            TiffinItem::destroy($this->confirmingDeleteId);
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-tiffin-item-deletion');
        $this->dispatch('tiffin-item-catalog-updated');
        session()->flash('status', 'Tiffin item deleted.');
    }

    public function with(): array
    {
        return [
            'items' => TiffinItem::orderBy('name')->get(),
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Tiffin Item Catalog</h2>
        <x-primary-button type="button" wire:click="startCreate">
            + Add Item
        </x-primary-button>
    </div>

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
        <x-empty-state title="No tiffin items yet" message="Add items like Egg, Banana or Bread to build department recipes." />
    @endforelse

    <x-modal name="tiffin-item-form" focusable>
        <form wire:submit="save" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingId ? 'Edit Tiffin Item' : 'Add Tiffin Item' }}
            </h2>

            <div>
                <x-input-label for="item_name" value="Name" />
                <x-text-input wire:model="name" id="item_name" placeholder="e.g. Egg" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="item_unit_label" value="Unit Label" />
                <x-text-input wire:model="unit_label" id="item_unit_label" placeholder="e.g. pcs, litre (optional)" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('unit_label')" class="mt-2" />
            </div>

            <div class="flex items-center gap-2">
                <input type="checkbox" wire:model="is_active" id="item_is_active" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                <x-input-label for="item_is_active" value="Active" class="!mb-0" />
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

    <x-modal name="confirm-tiffin-item-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this item?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">This cannot be undone.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="delete">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="tiffin-item-delete-blocked" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Can't delete this item</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $deleteBlockedMessage }}</p>
            <div class="mt-6 flex justify-end">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Close</x-secondary-button>
            </div>
        </div>
    </x-modal>
</div>
