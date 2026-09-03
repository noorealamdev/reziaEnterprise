<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
                {{ __('Staff') }}
            </h2>
            @can('employees.create')
                <x-primary-button :href="route('employees.create')" wire:navigate>
                    New Staff
                </x-primary-button>
            @endcan
        </div>
    </x-slot>

    <livewire:employees.employee-list />
</x-app-layout>
