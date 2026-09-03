<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Edit Staff') }}
        </h2>
    </x-slot>

    <livewire:employees.employee-form :employee="$employee" />
</x-app-layout>
