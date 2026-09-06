<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
                {{ __('Loading Unloading Items') }}
            </h2>
            <x-secondary-button :href="route('service-categories.index')" wire:navigate>
                Back to Service Categories
            </x-secondary-button>
        </div>
    </x-slot>

    <livewire:loading-unloading-items.item-catalog-manager />
</x-app-layout>
