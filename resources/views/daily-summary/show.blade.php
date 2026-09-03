<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
                {{ __('Daily Summary') }}
            </h2>
            <x-secondary-button :href="route('daily-summary.index', ['company' => $company->id])" wire:navigate>
                Back
            </x-secondary-button>
        </div>
    </x-slot>

    <livewire:daily-summary.daily-summary-detail :company="$company" :date="$date" />
</x-app-layout>
