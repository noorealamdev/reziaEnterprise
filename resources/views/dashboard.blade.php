<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
                {{ __('Dashboard') }}
            </h2>
            @can('job_entries.create')
                <x-primary-button :href="route('job-entries.create')" wire:navigate>
                    New Entry
                </x-primary-button>
            @endcan
        </div>
    </x-slot>

    <livewire:dashboard.dashboard />
</x-app-layout>
