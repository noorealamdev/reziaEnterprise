<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Egg Purchase & Stock Management') }}
        </h2>
    </x-slot>

    <div class="space-y-8">
        <livewire:egg-purchases.purchase-manager />
    </div>
</x-app-layout>
