<?php

use App\Models\Employee;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    public ?int $confirmingDeleteId = null;

    public string $deleteBlockedMessage = '';

    /**
     * Captured once in mount() — a paginator built or re-resolved mid-session
     * would otherwise take its path from request()->url(), which resolves to
     * Livewire's own update endpoint during an AJAX re-render, not this
     * page's real URL.
     */
    public string $paginationPath = '';

    public function mount(): void
    {
        $this->paginationPath = request()->url();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function confirmDelete(int $employeeId): void
    {
        $employee = Employee::findOrFail($employeeId);

        if ($employee->salaryPayments()->exists()) {
            $this->deleteBlockedMessage = 'This staff member has recorded salary payments and can\'t be deleted. Remove the payments first if they genuinely need to be removed.';
            $this->dispatch('open-modal', 'employee-delete-blocked');

            return;
        }

        $this->confirmingDeleteId = $employeeId;
        $this->dispatch('open-modal', 'confirm-employee-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('employees.modify');

        if ($this->confirmingDeleteId) {
            Employee::destroy($this->confirmingDeleteId);
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-employee-deletion');
    }

    public function with(): array
    {
        return [
            'employees' => Employee::query()
                ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
                ->orderBy('name')
                ->simplePaginate(10)
                ->setPath($this->paginationPath)
                ->appends(array_filter(['q' => $this->search])),
        ];
    }
}; ?>

<div class="space-y-4">
    <x-text-input
        type="search"
        wire:model.live.debounce.400ms="search"
        placeholder="Search by name…"
        class="w-full"
    />

    @forelse ($employees as $employee)
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $employee->name }}</h3>
                        <x-badge color="{{ $employee->is_active ? 'green' : 'slate' }}">
                            {{ $employee->is_active ? 'Active' : 'Inactive' }}
                        </x-badge>
                    </div>
                    @if ($employee->position)
                        <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ $employee->position }}</p>
                    @endif

                    <div class="mt-2 space-y-0.5 text-sm text-slate-600 dark:text-slate-400">
                        <p>Monthly salary: <span class="font-medium text-slate-800 dark:text-slate-200">{{ number_format((float) $employee->monthly_salary, 2) }}</span></p>
                        @if ($employee->phone)
                            <p>{{ $employee->phone }}</p>
                        @endif
                    </div>
                </div>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <x-primary-button :href="route('employees.show', $employee)" wire:navigate>
                    View
                </x-primary-button>
                @can('employees.modify')
                    <x-secondary-button :href="route('employees.edit', $employee)" wire:navigate>
                        Edit
                    </x-secondary-button>
                    <x-danger-button type="button" wire:click="confirmDelete({{ $employee->id }})">
                        Delete
                    </x-danger-button>
                @endcan
            </div>
        </div>
    @empty
        <x-empty-state
            :title="$search ? 'No staff match “'.$search.'”' : 'No staff yet'"
            :message="$search ? 'Try a different name.' : 'Add your first staff member to get started.'"
        >
            @unless ($search)
                @can('employees.create')
                    <x-slot:action>
                        <x-primary-button :href="route('employees.create')" wire:navigate>
                            New Staff
                        </x-primary-button>
                    </x-slot:action>
                @endcan
            @endunless
        </x-empty-state>
    @endforelse

    {{ $employees->links('pagination::simple-tailwind') }}

    <x-modal name="confirm-employee-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                Delete this staff member?
            </h2>

            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                This cannot be undone.
            </p>

            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-danger-button type="button" wire:click="delete">
                    Delete
                </x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="employee-delete-blocked" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Can't delete this staff member</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $deleteBlockedMessage }}</p>
            <div class="mt-6 flex justify-end">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Close</x-secondary-button>
            </div>
        </div>
    </x-modal>
</div>
