<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Edit Tiffin Order') }}
        </h2>
    </x-slot>

    <livewire:job-entries.tiffin-batch-edit-form :company="$company" :department="$department" :date="$date" />
</x-app-layout>
