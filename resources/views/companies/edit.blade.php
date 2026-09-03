<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Edit Company') }}
        </h2>
    </x-slot>

    <livewire:companies.company-form :company="$company" />
</x-app-layout>
