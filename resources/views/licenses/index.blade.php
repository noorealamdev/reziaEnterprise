<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Licenses') }}
        </h2>
    </x-slot>

    <livewire:licenses.license-manager />
</x-app-layout>
