<?php

use App\Models\JobEntry;
use App\Models\ServiceCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $invoice_code = '';

    public ?string $unit_label = null;

    public ?int $confirmingDeleteId = null;

    public string $deleteBlockedMessage = '';

    /**
     * Categories with their own bespoke fields in the Job Entry form
     * (looked up there by exact name — see job-entry-form.blade.php).
     * Renaming one here would silently disconnect that custom behavior
     * until the matching code is updated too.
     *
     * @var array<int, string>
     */
    private const SPECIAL_NAMES = [
        'Tiffin',
        'Embroidery & Print',
        'Loading Unloading',
        'Diesel Oil Supply',
        'ETP Eid Holiday',
    ];

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->invoice_code = '';
        $this->unit_label = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'category-form');
    }

    public function startEdit(int $categoryId): void
    {
        $category = ServiceCategory::findOrFail($categoryId);
        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->invoice_code = $category->invoice_code;
        $this->unit_label = $category->unit_label;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'category-form');
    }

    public function save(): void
    {
        Gate::authorize('service_categories.manage');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('service_categories', 'name')->ignore($this->editingId)],
            'invoice_code' => ['required', 'string', 'max:50', Rule::unique('service_categories', 'invoice_code')->ignore($this->editingId)],
            'unit_label' => ['nullable', 'string', 'max:50'],
        ]);

        if ($this->editingId) {
            ServiceCategory::whereKey($this->editingId)->update($validated);
        } else {
            $validated['sort_order'] = (int) ServiceCategory::max('sort_order') + 1;
            ServiceCategory::create($validated);
        }

        $this->dispatch('close-modal', 'category-form');
        session()->flash('status', $this->editingId ? 'Service category updated.' : 'Service category added.');
    }

    public function confirmDelete(int $categoryId): void
    {
        $category = ServiceCategory::withCount('companies')->findOrFail($categoryId);

        $inUse = $category->companies_count > 0
            || JobEntry::where('service_category_id', $categoryId)->exists();

        if ($inUse) {
            $this->deleteBlockedMessage = "\"{$category->name}\" is still in use (assigned to companies or has job entries) and can't be deleted. Remove those first.";
            $this->dispatch('open-modal', 'category-delete-blocked');

            return;
        }

        $this->confirmingDeleteId = $categoryId;
        $this->dispatch('open-modal', 'confirm-category-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('service_categories.manage');

        if ($this->confirmingDeleteId) {
            ServiceCategory::destroy($this->confirmingDeleteId);
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-category-deletion');
        session()->flash('status', 'Service category deleted.');
    }

    public function moveUp(int $categoryId): void
    {
        $this->swapOrder($categoryId, 'up');
    }

    public function moveDown(int $categoryId): void
    {
        $this->swapOrder($categoryId, 'down');
    }

    private function swapOrder(int $categoryId, string $direction): void
    {
        Gate::authorize('service_categories.manage');

        $category = ServiceCategory::findOrFail($categoryId);

        $neighbor = ServiceCategory::when(
            $direction === 'up',
            fn ($query) => $query->where('sort_order', '<', $category->sort_order)->orderByDesc('sort_order'),
            fn ($query) => $query->where('sort_order', '>', $category->sort_order)->orderBy('sort_order'),
        )->first();

        if (! $neighbor) {
            return;
        }

        DB::transaction(function () use ($category, $neighbor) {
            [$order, $neighborOrder] = [$category->sort_order, $neighbor->sort_order];
            $category->update(['sort_order' => $neighborOrder]);
            $neighbor->update(['sort_order' => $order]);
        });
    }

    public function with(): array
    {
        $jobEntryCounts = JobEntry::selectRaw('service_category_id, count(*) as aggregate')
            ->groupBy('service_category_id')
            ->pluck('aggregate', 'service_category_id');

        return [
            'categories' => ServiceCategory::withCount('companies')->orderBy('sort_order')->get(),
            'jobEntryCounts' => $jobEntryCounts,
            'specialNames' => self::SPECIAL_NAMES,
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">Service Categories</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                What this business supplies. Add a new one here and it's immediately available for companies to subscribe to and for job entries — no code changes needed.
            </p>
        </div>
        <x-secondary-button type="button" wire:click="startCreate">
            + Add Category
        </x-secondary-button>
    </div>

    @if ($categories->isEmpty())
        <x-empty-state title="No service categories yet" message="Add the first one your business supplies, e.g. Daily Basic Labour." />
    @else
        <div class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white dark:divide-slate-700/50 dark:border-slate-800 dark:bg-slate-800">
            @foreach ($categories as $category)
                <div class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ $category->name }}</span>
                            <x-badge>{{ $category->invoice_code }}</x-badge>
                            @if ($category->unit_label)
                                <span class="text-xs text-slate-400 dark:text-slate-500">per {{ $category->unit_label }}</span>
                            @endif
                            @if (in_array($category->name, $specialNames, true))
                                <span class="text-xs text-amber-600 dark:text-amber-400" title="This name is matched exactly in the Job Entry form to show its custom fields. Renaming it will disconnect those fields until the code is updated too.">
                                    Has custom fields
                                </span>
                            @endif
                        </div>
                        <p class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">
                            {{ $category->companies_count }} {{ Str::plural('company', $category->companies_count) }} subscribed
                            · {{ $jobEntryCounts[$category->id] ?? 0 }} {{ Str::plural('entry', $jobEntryCounts[$category->id] ?? 0) }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <button type="button" wire:click="moveUp({{ $category->id }})" class="rounded p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-700 dark:hover:text-slate-300" title="Move up">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7" /></svg>
                        </button>
                        <button type="button" wire:click="moveDown({{ $category->id }})" class="rounded p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-700 dark:hover:text-slate-300" title="Move down">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7" /></svg>
                        </button>
                        @if ($category->name === 'Tiffin')
                            <x-secondary-button :href="route('tiffin-items.index')" wire:navigate class="!px-2 !py-1 !text-xs">
                                Manage Items
                            </x-secondary-button>
                        @elseif ($category->name === 'Loading Unloading')
                            <x-secondary-button :href="route('loading-unloading-items.index')" wire:navigate class="!px-2 !py-1 !text-xs">
                                Manage Items
                            </x-secondary-button>
                        @endif
                        <x-secondary-button type="button" wire:click="startEdit({{ $category->id }})" class="!px-2 !py-1 !text-xs">
                            Edit
                        </x-secondary-button>
                        <x-danger-button type="button" wire:click="confirmDelete({{ $category->id }})" class="!px-2 !py-1 !text-xs">
                            Delete
                        </x-danger-button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <x-modal name="category-form" focusable>
        <form wire:submit="save" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingId ? 'Edit Service Category' : 'Add Service Category' }}
            </h2>

            <div>
                <x-input-label for="category_name" value="Name" />
                <x-text-input wire:model="name" id="category_name" placeholder="e.g. Security Guard Supply" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="category_invoice_code" value="Invoice Code" />
                <x-text-input wire:model="invoice_code" id="category_invoice_code" placeholder="e.g. SEC" class="mt-1 block w-full" required />
                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Short code used to label this category's rows on an invoice.</p>
                <x-input-error :messages="$errors->get('invoice_code')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="category_unit_label" value="Unit Label" />
                <x-text-input wire:model="unit_label" id="category_unit_label" placeholder="e.g. workers, trips, litres" class="mt-1 block w-full" />
                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Optional — shown next to the quantity, e.g. "5 workers".</p>
                <x-input-error :messages="$errors->get('unit_label')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-primary-button>
                    {{ $editingId ? 'Save Changes' : 'Add Category' }}
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-category-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this category?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">This cannot be undone.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="delete">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="category-delete-blocked" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Can't delete this category</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $deleteBlockedMessage }}</p>
            <div class="mt-6 flex justify-end">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Close</x-secondary-button>
            </div>
        </div>
    </x-modal>
</div>
