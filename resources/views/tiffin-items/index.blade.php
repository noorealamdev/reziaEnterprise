<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
                {{ __('Tiffin Items') }}
            </h2>
            <x-secondary-button :href="route('service-categories.index')" wire:navigate>
                Back to Service Categories
            </x-secondary-button>
        </div>
    </x-slot>

    <div class="space-y-8">
        <livewire:tiffin-items.department-manager />
        <livewire:tiffin-items.item-catalog-manager />
        <livewire:tiffin-items.department-recipe-manager />
    </div>
</x-app-layout>
