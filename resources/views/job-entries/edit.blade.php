<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Edit Job Entry') }}
        </h2>
    </x-slot>

    <livewire:job-entries.job-entry-form :job-entry="$jobEntry" />
</x-app-layout>
