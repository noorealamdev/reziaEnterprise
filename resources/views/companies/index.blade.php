<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
                {{ __('Companies') }}
            </h2>
            @can('companies.create')
                <x-primary-button :href="route('companies.create')" wire:navigate>
                    New Company
                </x-primary-button>
            @endcan
        </div>
    </x-slot>

    <livewire:companies.company-list />
</x-app-layout>
