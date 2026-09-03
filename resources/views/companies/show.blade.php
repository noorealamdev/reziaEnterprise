<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
                {{ __('Company Overview') }}
            </h2>
            <x-secondary-button :href="route('companies.index')" wire:navigate>
                Back to Companies
            </x-secondary-button>
        </div>
    </x-slot>

    <livewire:companies.company-detail :company="$company" />
</x-app-layout>
