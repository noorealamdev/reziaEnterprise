<?php

use App\Models\Company;
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

    public function confirmDelete(int $companyId): void
    {
        $this->confirmingDeleteId = $companyId;
        $this->dispatch('open-modal', 'confirm-company-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('companies.modify');

        if ($this->confirmingDeleteId) {
            Company::destroy($this->confirmingDeleteId);
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-company-deletion');
    }

    public function with(): array
    {
        return [
            'companies' => Company::query()
                ->when($this->search, fn ($query) => $query->where(fn ($query) => $query
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('code', 'like', "%{$this->search}%")))
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
        placeholder="Search by name or code…"
        class="w-full"
    />

    @forelse ($companies as $company)
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $company->name }}</h3>
                        <x-badge color="{{ $company->is_active ? 'green' : 'slate' }}">
                            {{ $company->is_active ? 'Active' : 'Inactive' }}
                        </x-badge>
                    </div>
                    <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ $company->code }}</p>

                    <div class="mt-2 space-y-0.5 text-sm text-slate-600 dark:text-slate-400">
                        @if ($company->contact_person)
                            <p>{{ $company->contact_person }}</p>
                        @endif
                        @if ($company->phone)
                            <p>{{ $company->phone }}</p>
                        @endif
                        @if ($company->address)
                            <p class="truncate">{{ $company->address }}</p>
                        @endif
                    </div>
                </div>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <x-primary-button :href="route('companies.show', $company)" wire:navigate>
                    View
                </x-primary-button>
                @can('companies.modify')
                    <x-secondary-button :href="route('companies.edit', $company)" wire:navigate>
                        Edit
                    </x-secondary-button>
                    <x-danger-button type="button" wire:click="confirmDelete({{ $company->id }})">
                        Delete
                    </x-danger-button>
                @endcan
            </div>
        </div>
    @empty
        <x-empty-state
            :title="$search ? 'No companies match “'.$search.'”' : 'No companies yet'"
            :message="$search ? 'Try a different name or code.' : 'Add your first company to get started.'"
        >
            @unless ($search)
                @can('companies.create')
                    <x-slot:action>
                        <x-primary-button :href="route('companies.create')" wire:navigate>
                            New Company
                        </x-primary-button>
                    </x-slot:action>
                @endcan
            @endunless
        </x-empty-state>
    @endforelse

    {{ $companies->links('pagination::simple-tailwind') }}

    <x-modal name="confirm-company-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                Delete this company?
            </h2>

            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                This will permanently remove the company and its service/department assignments. This cannot be undone.
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
</div>
