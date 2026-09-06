<?php

use App\Models\Company;
use App\Models\JobEntry;
use Livewire\Volt\Component;

new class extends Component
{
    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function with(): array
    {
        $totalEntries = JobEntry::where('company_id', $this->company->id)->count();

        $unbilledQuery = JobEntry::where('company_id', $this->company->id)->whereNull('invoice_id');
        $unbilledCount = (clone $unbilledQuery)->count();
        $unbilledTotal = (clone $unbilledQuery)->sum('bill_amount');

        $lifetimeBillTotal = JobEntry::where('company_id', $this->company->id)->sum('bill_amount');

        // Fetch a wider raw window than the 10 we'll display, since Tiffin
        // entries for the same department/day collapse into a single row
        // below (Tiffin is billed as one package, not per ingredient) —
        // otherwise a busy Tiffin day could crowd out other categories
        // entirely.
        $recentEntries = JobEntry::where('company_id', $this->company->id)
            ->with(['serviceCategory', 'tiffinDepartment'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->groupBy(fn (JobEntry $entry) => $entry->tiffin_department_id
                ? "tiffin-{$entry->tiffin_department_id}-{$entry->entry_date->toDateString()}"
                : "single-{$entry->id}")
            ->map(function ($group) {
                $first = $group->first();

                return (object) [
                    'entry_date' => $first->entry_date,
                    'serviceCategory' => $first->serviceCategory,
                    'label' => $first->tiffin_department_id ? $first->tiffinDepartment->name : $first->supply_type,
                    'items' => $first->tiffin_department_id ? $group->pluck('supply_type')->unique()->sort()->implode(', ') : null,
                    'billAmount' => (float) $group->sum('bill_amount'),
                    'isBilled' => $group->contains(fn (JobEntry $entry) => $entry->isBilled),
                    'editUrl' => $first->tiffin_department_id
                        ? route('job-entries.batch-edit', [
                            'company' => $first->company_id,
                            'tiffinDepartment' => $first->tiffin_department_id,
                            'date' => $first->entry_date->toDateString(),
                        ])
                        : route('job-entries.edit', $first),
                    'sortId' => $first->id,
                ];
            })
            ->sortByDesc(fn ($row) => $row->entry_date->format('Y-m-d').'-'.str_pad((string) $row->sortId, 10, '0', STR_PAD_LEFT))
            ->take(10)
            ->values();

        return [
            'totalEntries' => $totalEntries,
            'unbilledCount' => $unbilledCount,
            'unbilledTotal' => $unbilledTotal,
            'lifetimeBillTotal' => $lifetimeBillTotal,
            'recentEntries' => $recentEntries,
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-800">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $company->name }}</h2>
                    <x-badge color="{{ $company->is_active ? 'green' : 'slate' }}">
                        {{ $company->is_active ? 'Active' : 'Inactive' }}
                    </x-badge>
                </div>
                <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ $company->code }}</p>

                <div class="mt-2 space-y-0.5 text-sm text-slate-600 dark:text-slate-400">
                    @if ($company->contact_person)
                        <p>{{ $company->contact_person }}</p>
                    @endif
                    @if ($company->phone)
                        <p>{{ $company->phone }}</p>
                    @endif
                    @if ($company->address)
                        <p>{{ $company->address }}</p>
                    @endif
                </div>
            </div>

            @can('companies.modify')
                <x-secondary-button :href="route('companies.edit', $company)" wire:navigate>
                    Edit Company
                </x-secondary-button>
            @endcan
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Total Entries" :value="$totalEntries">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="5" y="4" width="14" height="17" rx="2" />
                    <path d="M8.5 10h7M8.5 13.5h7M8.5 17h4" />
                </svg>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card label="Unbilled" :value="number_format((float) $unbilledTotal, 2)" :hint="$unbilledCount.' '.Str::plural('entry', $unbilledCount).' not yet invoiced'">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M7 3h7l4 4v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z" />
                    <path d="M14 3v4h4" />
                </svg>
            </x-slot:icon>
        </x-stat-card>

        <x-stat-card label="Lifetime Billed" :value="number_format((float) $lifetimeBillTotal, 2)">
            <x-slot:icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M11 3H5a2 2 0 0 0-2 2v6l10 10 8-8L11 3z" />
                    <circle cx="7.5" cy="7.5" r="1.25" />
                </svg>
            </x-slot:icon>
        </x-stat-card>
    </div>

    <div class="flex flex-wrap gap-3">
        @can('job_entries.create')
            <x-primary-button :href="route('job-entries.create', ['company' => $company->id])" wire:navigate>
                New Entry
            </x-primary-button>
        @endcan
        @can('invoices.create')
            <x-secondary-button :href="route('invoices.create', ['company' => $company->id])" wire:navigate>
                Generate Invoice
            </x-secondary-button>
        @endcan
        <x-secondary-button :href="route('daily-summary.index', ['company' => $company->id])" wire:navigate>
            Daily Summary
        </x-secondary-button>
        <x-secondary-button :href="route('bill-statement.index', ['company' => $company->id])" wire:navigate>
            Bill Statement
        </x-secondary-button>
        @can('company_purchases.view')
            <x-secondary-button :href="route('company-purchases.index', ['company' => $company->id])" wire:navigate>
                Goods Purchased From Them
            </x-secondary-button>
        @endcan
        @can('company_agreements.view')
            <x-secondary-button :href="route('company-agreements.index', ['company' => $company->id])" wire:navigate>
                Agreements
            </x-secondary-button>
        @endcan
    </div>

    <div>
        <div class="mb-2 flex items-center justify-between px-1">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300">Recent Entries</h3>
            <a href="{{ route('job-entries.index', ['company' => $company->id]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                View All →
            </a>
        </div>

        @if ($recentEntries->isEmpty())
            <x-empty-state
                title="No job entries yet"
                message="Add the first entry for this company to get started."
            >
                <x-slot:action>
                    <x-primary-button :href="route('job-entries.create', ['company' => $company->id])" wire:navigate>
                        New Entry
                    </x-primary-button>
                </x-slot:action>
            </x-empty-state>
        @else
            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <table class="w-full min-w-[560px] text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                            <th class="px-4 py-2">Date</th>
                            <th class="px-4 py-2">Category</th>
                            <th class="px-4 py-2">Item</th>
                            <th class="px-4 py-2 text-right">Bill</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentEntries as $entry)
                            <tr class="border-b border-slate-100 last:border-0 dark:border-slate-700/50">
                                <td class="px-4 py-2 text-slate-600 dark:text-slate-400">{{ $entry->entry_date->format('d M Y') }}</td>
                                <td class="px-4 py-2">
                                    <x-badge color="brand">{{ $entry->serviceCategory->name }}</x-badge>
                                </td>
                                <td class="px-4 py-2 text-slate-700 dark:text-slate-300">
                                    {{ $entry->label }}
                                    @if ($entry->items)
                                        <span class="block text-xs text-slate-400 dark:text-slate-500">{{ $entry->items }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right font-medium text-slate-800 dark:text-slate-200">{{ number_format($entry->billAmount, 2) }}</td>
                                <td class="px-4 py-2 text-right">
                                    @if ($entry->isBilled)
                                        <span class="text-xs text-slate-400 dark:text-slate-500">Billed</span>
                                    @else
                                        <a href="{{ $entry->editUrl }}" wire:navigate class="text-xs font-medium text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">Edit</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
