<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Daily Summary') }}
        </h2>
    </x-slot>

    <livewire:daily-summary.daily-summary-list />
</x-app-layout>
