<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Tiffin Purchases') }}
        </h2>
    </x-slot>

    <div class="space-y-8">
        <livewire:tiffin-purchases.purchase-manager />
    </div>
</x-app-layout>
