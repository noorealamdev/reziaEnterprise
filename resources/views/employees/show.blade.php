<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
                {{ __('Staff Overview') }}
            </h2>
            <x-secondary-button :href="route('staff-salaries.index')" wire:navigate>
                Back to Staff Salaries
            </x-secondary-button>
        </div>
    </x-slot>

    <livewire:employees.employee-detail :employee="$employee" />
</x-app-layout>
