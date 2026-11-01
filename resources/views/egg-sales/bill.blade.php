<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Egg Bill') }} — {{ $bill->invoice_number }}
        </h2>
    </x-slot>

    <livewire:egg-sales.bill-detail :bill="$bill" />
</x-app-layout>
