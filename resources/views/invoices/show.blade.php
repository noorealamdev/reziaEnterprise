<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Invoice') }} — {{ $invoice->invoice_number }}
        </h2>
    </x-slot>

    <livewire:invoices.invoice-detail :invoice="$invoice" />
</x-app-layout>
