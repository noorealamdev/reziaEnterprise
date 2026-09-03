<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Service Categories') }}
        </h2>
    </x-slot>

    <livewire:service-categories.category-manager />
</x-app-layout>
