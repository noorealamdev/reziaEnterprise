<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">
            {{ __('Canteen') }}
        </h2>
    </x-slot>

    <div class="space-y-8">
        <livewire:canteen.ledger-manager />
    </div>
</x-app-layout>
