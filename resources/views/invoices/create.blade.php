<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Generate Invoice') }}
        </h2>
    </x-slot>

    <livewire:invoices.invoice-generate-form />
</x-app-layout>
