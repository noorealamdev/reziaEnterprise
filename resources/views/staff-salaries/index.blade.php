<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
                {{ __('Staff Salaries') }}
            </h2>
            @can('employees.view')
                <x-secondary-button :href="route('employees.index')" wire:navigate>
                    Manage Staff
                </x-secondary-button>
            @endcan
        </div>
    </x-slot>

    <livewire:staff-salaries.staff-salaries />
</x-app-layout>
