<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Company Purchases') }}
        </h2>
    </x-slot>

    <livewire:company-purchases.purchase-manager />
</x-app-layout>
