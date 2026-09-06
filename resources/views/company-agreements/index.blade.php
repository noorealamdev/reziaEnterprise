<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Company Agreements') }}
        </h2>
    </x-slot>

    <livewire:company-agreements.agreement-manager />
</x-app-layout>
