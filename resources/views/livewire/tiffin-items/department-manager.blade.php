<?php

use App\Models\JobEntry;
use App\Models\TiffinDepartment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public ?int $confirmingDeleteId = null;

    public string $deleteBlockedMessage = '';

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'department-form');
    }

    public function startEdit(int $departmentId): void
    {
        $department = TiffinDepartment::findOrFail($departmentId);
        $this->editingId = $department->id;
        $this->name = $department->name;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'department-form');
    }

    public function save(): void
    {
        Gate::authorize('service_categories.manage');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('tiffin_departments', 'name')->ignore($this->editingId)],
        ]);

        if ($this->editingId) {
            TiffinDepartment::whereKey($this->editingId)->update($validated);
        } else {
            TiffinDepartment::create($validated);
        }

        $this->dispatch('close-modal', 'department-form');
        $this->dispatch('tiffin-department-updated');
        session()->flash('status', $this->editingId ? 'Department updated.' : 'Department added.');
    }

    public function confirmDelete(int $departmentId): void
    {
        $department = TiffinDepartment::withCount(['departmentItems', 'companies'])->findOrFail($departmentId);

        $inUse = $department->department_items_count > 0
            || $department->companies_count > 0
            || JobEntry::where('tiffin_department_id', $departmentId)->exists();

        if ($inUse) {
            $this->deleteBlockedMessage = "\"{$department->name}\" is still in use (recipe items, company assignments, or job entries) and can't be deleted. Remove those first.";
            $this->dispatch('open-modal', 'department-delete-blocked');

            return;
        }

        $this->confirmingDeleteId = $departmentId;
        $this->dispatch('open-modal', 'confirm-department-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('service_categories.manage');

        if ($this->confirmingDeleteId) {
            TiffinDepartment::destroy($this->confirmingDeleteId);
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-department-deletion');
        $this->dispatch('tiffin-department-updated');
        session()->flash('status', 'Department deleted.');
    }

    public function with(): array
    {
        return [
            'departments' => TiffinDepartment::withCount('departmentItems')->orderBy('name')->get(),
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Tiffin Departments</h2>
        <x-secondary-button type="button" wire:click="startCreate">
            + Add Department
        </x-secondary-button>
    </div>

    @if ($departments->isEmpty())
        <x-empty-state title="No departments yet" message="Add a department like Swing or Wash Worker to start building recipes." />
    @else
        <div class="flex flex-wrap gap-2">
            @foreach ($departments as $department)
                <div class="flex items-center gap-2 rounded-full border border-slate-200 bg-white py-1.5 pl-3 pr-1.5 text-sm dark:border-slate-800 dark:bg-slate-800">
                    <span class="font-medium text-slate-800 dark:text-slate-200">{{ $department->name }}</span>
                    <x-badge>{{ $department->department_items_count }} {{ Str::plural('item', $department->department_items_count) }}</x-badge>
                    <button type="button" wire:click="startEdit({{ $department->id }})" class="rounded-full p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-700 dark:hover:text-slate-300" title="Edit">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-6M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" /></svg>
                    </button>
                    <button type="button" wire:click="confirmDelete({{ $department->id }})" class="rounded-full p-1 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30 dark:hover:text-red-400" title="Delete">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6h16z" /></svg>
                    </button>
                </div>
            @endforeach
        </div>
    @endif

    <x-modal name="department-form" focusable>
        <form wire:submit="save" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingId ? 'Edit Department' : 'Add Department' }}
            </h2>

            <div>
                <x-input-label for="department_name" value="Name" />
                <x-text-input wire:model="name" id="department_name" placeholder="e.g. Cutting" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-primary-button>
                    {{ $editingId ? 'Save Changes' : 'Add Department' }}
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-department-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this department?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">This cannot be undone.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="delete">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="department-delete-blocked" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Can't delete this department</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $deleteBlockedMessage }}</p>
            <div class="mt-6 flex justify-end">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Close</x-secondary-button>
            </div>
        </div>
    </x-modal>
</div>
