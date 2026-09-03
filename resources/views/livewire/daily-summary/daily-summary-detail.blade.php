<?php

use App\Models\Company;
use App\Models\JobEntry;
use Illuminate\Support\Carbon;
use Livewire\Volt\Component;

new class extends Component
{
    public Company $company;

    public string $date;

    public function mount(Company $company, string $date): void
    {
        $this->company = $company;
        $this->date = $date;
    }

    public function with(): array
    {
        $entries = JobEntry::where('company_id', $this->company->id)
            ->whereDate('entry_date', $this->date)
            ->with(['serviceCategory', 'tiffinDepartment'])
            ->orderBy('service_category_id')
            ->orderBy('id')
            ->get();

        $categories = $entries->groupBy('service_category_id')
            ->map(fn ($group) => [
                'category' => $group->first()->serviceCategory,
                'entries' => $group,
                'costAmount' => $group->sum('cost_amount'),
                'billAmount' => $group->sum('bill_amount'),
                'profitAmount' => $group->sum('profit_amount'),
            ])
            ->sortBy(fn ($group) => $group['category']->sort_order)
            ->values();

        return [
            'categories' => $categories,
            'entryCount' => $entries->count(),
            'totalCostAmount' => $entries->sum('cost_amount'),
            'totalBillAmount' => $entries->sum('bill_amount'),
            'totalProfitAmount' => $entries->sum('profit_amount'),
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-800">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $company->name }}</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ Carbon::parse($date)->format('l, d F Y') }}</p>
            </div>
            <x-badge color="brand">{{ $entryCount }} {{ Str::plural('entry', $entryCount) }}</x-badge>
        </div>
    </div>

    @if ($categories->isEmpty())
        <x-empty-state title="Nothing recorded for this day" message="No job entries were found for this company on this date." />
    @else
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <table class="w-full min-w-[720px] text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                        <th class="px-4 py-2">Item</th>
                        <th class="px-4 py-2 text-right">Quantity</th>
                        <th class="px-4 py-2 text-right">Cost Rate</th>
                        <th class="px-4 py-2 text-right">Bill Rate</th>
                        <th class="px-4 py-2 text-right">Cost</th>
                        <th class="px-4 py-2 text-right">Bill</th>
                        <th class="px-4 py-2 text-right">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($categories as $group)
                        <tr class="bg-brand-50 dark:bg-brand-900/20">
                            <td colspan="7" class="px-4 py-2 text-sm font-semibold text-slate-900 dark:text-white">
                                {{ $group['category']->name }}
                            </td>
                        </tr>

                        @if ($group['entries']->first()->tiffin_department_id)
                            {{-- Tiffin is billed as one package per department, not per
                            ingredient — so this shows one row per department instead of
                            one per item (Banana/Egg/Bread). Only Egg's row actually
                            bills (a fixed rate per person); Banana/Bread/any exchange
                            item are cost-tracking only. Egg's *stored* quantity includes
                            the always-sent +5 buffer on top of headcount, so headcount
                            and the bill rate per person come from the billing row's own
                            bill_amount/bill_rate — never a division that mixes the
                            buffered count back in. --}}
                            @foreach ($group['entries']->groupBy('tiffin_department_id') as $batch)
                                @php
                                    $batchFirst = $batch->first();
                                    $batchCost = (float) $batch->sum('cost_amount');
                                    $batchBill = (float) $batch->sum('bill_amount');
                                    $billingEntry = $batch->first(fn ($entry) => (float) $entry->bill_amount > 0);
                                    $billRate = (float) ($billingEntry->bill_rate ?? 0);
                                    $headcount = $billRate > 0 ? round($batchBill / $billRate, 2) : (float) ($billingEntry->quantity ?? 0);
                                @endphp
                                <tr class="border-b border-slate-100 dark:border-slate-700/50">
                                    <td class="px-4 py-2 text-slate-700 dark:text-slate-300">
                                        {{ $batchFirst->tiffinDepartment->name }}
                                        <span class="text-xs text-slate-400 dark:text-slate-500">— {{ $batch->pluck('supply_type')->unique()->sort()->implode(', ') }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-right text-slate-600 dark:text-slate-400">{{ number_format($headcount, 2) }}</td>
                                    <td class="px-4 py-2 text-right text-slate-600 dark:text-slate-400" title="Per person served">{{ $headcount > 0 ? number_format($batchCost / $headcount, 2) : '—' }}</td>
                                    <td class="px-4 py-2 text-right text-slate-600 dark:text-slate-400" title="Per person served">{{ $headcount > 0 ? number_format($billRate, 2) : '—' }}</td>
                                    <td class="px-4 py-2 text-right text-slate-600 dark:text-slate-400">{{ number_format($batchCost, 2) }}</td>
                                    <td class="px-4 py-2 text-right font-medium text-slate-800 dark:text-slate-200">{{ number_format($batchBill, 2) }}</td>
                                    <td class="px-4 py-2 text-right text-slate-600 dark:text-slate-400">{{ number_format((float) $batch->sum('profit_amount'), 2) }}</td>
                                </tr>
                            @endforeach
                        @else
                            @foreach ($group['entries'] as $entry)
                                <tr class="border-b border-slate-100 dark:border-slate-700/50">
                                    <td class="px-4 py-2 text-slate-700 dark:text-slate-300">
                                        {{ $entry->supply_type }}
                                        @if ($entry->buyer)
                                            <span class="text-slate-400 dark:text-slate-500">— {{ $entry->buyer }}</span>
                                        @endif
                                        @if ($entry->style)
                                            <span class="text-slate-400 dark:text-slate-500">(Style {{ $entry->style }})</span>
                                        @endif
                                        @if ($entry->floor)
                                            <span class="text-slate-400 dark:text-slate-500">— {{ $entry->floor }}</span>
                                        @endif
                                        @if ($entry->is_off_day)
                                            <x-badge color="amber" class="ml-1">Off Day</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right text-slate-600 dark:text-slate-400">{{ number_format((float) $entry->quantity, 2) }}</td>
                                    <td class="px-4 py-2 text-right text-slate-600 dark:text-slate-400">{{ number_format((float) $entry->cost_rate, 2) }}</td>
                                    <td class="px-4 py-2 text-right text-slate-600 dark:text-slate-400">{{ number_format((float) $entry->bill_rate, 2) }}</td>
                                    <td class="px-4 py-2 text-right text-slate-600 dark:text-slate-400">{{ number_format((float) $entry->cost_amount, 2) }}</td>
                                    <td class="px-4 py-2 text-right font-medium text-slate-800 dark:text-slate-200">{{ number_format((float) $entry->bill_amount, 2) }}</td>
                                    <td class="px-4 py-2 text-right text-slate-600 dark:text-slate-400">{{ number_format((float) $entry->profit_amount, 2) }}</td>
                                </tr>
                            @endforeach
                        @endif

                        <tr class="border-b-2 border-slate-200 dark:border-slate-700">
                            <td colspan="4" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                Subtotal
                            </td>
                            <td class="px-4 py-2 text-right text-xs font-semibold text-slate-700 dark:text-slate-300">{{ number_format($group['costAmount'], 2) }}</td>
                            <td class="px-4 py-2 text-right text-xs font-semibold text-slate-700 dark:text-slate-300">{{ number_format($group['billAmount'], 2) }}</td>
                            <td class="px-4 py-2 text-right text-xs font-semibold text-slate-700 dark:text-slate-300">{{ number_format($group['profitAmount'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-slate-100 dark:bg-slate-900">
                        <td colspan="4" class="px-4 py-3 text-sm font-bold text-slate-900 dark:text-white">Grand Total</td>
                        <td class="px-4 py-3 text-right text-sm font-bold text-slate-900 dark:text-white">{{ number_format($totalCostAmount, 2) }}</td>
                        <td class="px-4 py-3 text-right text-sm font-bold text-slate-900 dark:text-white">{{ number_format($totalBillAmount, 2) }}</td>
                        <td class="px-4 py-3 text-right text-sm font-bold text-slate-900 dark:text-white">{{ number_format($totalProfitAmount, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
