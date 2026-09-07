<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Add Past Invoice') }}
        </h2>
    </x-slot>

    <livewire:invoices.manual-invoice-form />
</x-app-layout>
