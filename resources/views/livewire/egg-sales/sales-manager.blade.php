<?php

use App\Models\EggBuyer;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component
{
    #[Url(as: 'view', history: true)]
    public string $activeView = 'sales';

    public function switchView(string $view): void
    {
        $this->activeView = in_array($view, ['sales', 'bills', 'wallet', 'buyers'], true) ? $view : 'sales';
    }

    public function with(): array
    {
        return [
            'walletBuyerName' => EggBuyer::cashWalletBuyer()?->name,
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="inline-flex flex-wrap gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
        @foreach (array_filter(['sales' => 'Sales', 'bills' => 'Bills', 'wallet' => $walletBuyerName ? "{$walletBuyerName} Wallet" : null, 'buyers' => 'Buyers']) as $view => $label)
            <button
                type="button"
                wire:click="switchView('{{ $view }}')"
                class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $activeView === $view ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($activeView === 'bills')
        <livewire:egg-sales.bill-list wire:key="egg-bills-tab" />
    @elseif ($activeView === 'wallet' && $walletBuyerName)
        <livewire:egg-sales.cash-wallet wire:key="egg-wallet-tab" />
    @elseif ($activeView === 'buyers')
        <livewire:egg-sales.buyer-manager wire:key="egg-buyers-tab" />
    @else
        <livewire:egg-sales.sale-list wire:key="egg-sales-tab" />
    @endif
</div>
