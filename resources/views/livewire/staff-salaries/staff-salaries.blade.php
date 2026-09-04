<?php

use App\Models\Employee;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    private const PER_PAGE = 15;

    #[Url(as: 'month', history: true)]
    public string $period = '';

    #[Url(as: 'status', history: true)]
    public string $statusFilter = '';

    #[Url(as: 'q', history: true)]
    public string $search = '';

    /**
     * Captured once in mount() — a manually-built LengthAwarePaginator needs
     * an explicit 'path', and request()->url() would otherwise resolve to
     * Livewire's own update endpoint on any re-render triggered by a filter
     * change, not this page's real URL.
     */
    public string $paginationPath = '';

    public function mount(): void
    {
        $this->paginationPath = request()->url();

        if ($this->period === '') {
            $this->period = now()->format('Y-m');
        }
    }

    public function updatingPeriod(): void
    {
        if ($this->period === '') {
            $this->period = now()->format('Y-m');
        }

        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Every #[Url]-bound filter, appended onto the pagination links —
     * without this, clicking "Next" (a plain <a href>, not a wire:click)
     * does a full page reload to a URL holding only the page number,
     * silently resetting every filter back to its default.
     *
     * @return array<string, string>
     */
    private function urlQueryState(): array
    {
        return array_filter([
            'month' => $this->period,
            'status' => $this->statusFilter,
            'q' => $this->search,
        ], fn ($value) => $value !== '');
    }

    public function with(): array
    {
        $periodStart = Carbon::createFromFormat('Y-m', $this->period)->startOfMonth();

        // The full filtered set — totals below must reflect every matching
        // employee, not just whichever page is on screen, same reasoning
        // as Bill Statement's totals.
        $allRows = Employee::where('is_active', true)
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->withSum(['salaryPayments as paidForPeriod' => fn ($query) => $query->whereDate('for_month', $periodStart->toDateString())], 'amount')
            ->orderBy('name')
            ->get()
            ->map(function (Employee $employee) {
                $expected = (float) $employee->monthly_salary;
                $paid = (float) $employee->paidForPeriod;
                $balance = max(0, $expected - $paid);

                $status = match (true) {
                    $paid <= 0 => 'due',
                    $paid + 0.01 >= $expected => 'paid',
                    default => 'partial',
                };

                return (object) [
                    'employee' => $employee,
                    'expected' => $expected,
                    'paid' => $paid,
                    'balance' => $balance,
                    'status' => $status,
                ];
            })
            ->when($this->statusFilter, fn ($rows) => $rows->filter(fn ($row) => $row->status === $this->statusFilter))
            ->values();

        // $allRows is a computed collection, not a query, so it's paginated
        // by hand — same LengthAwarePaginator-around-a-slice convention
        // Daily Summary uses for its own computed day groupings.
        $rows = (new LengthAwarePaginator(
            $allRows->forPage($this->getPage(), self::PER_PAGE)->values(),
            $allRows->count(),
            self::PER_PAGE,
            $this->getPage(),
            ['pageName' => 'page', 'path' => $this->paginationPath]
        ))->appends($this->urlQueryState());

        return [
            'period' => $periodStart,
            'rows' => $rows,
            'totalExpected' => (float) $allRows->sum('expected'),
            'totalPaid' => (float) $allRows->sum('paid'),
            'totalOutstanding' => (float) $allRows->sum('balance'),
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <x-text-input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Search by name…"
                class="w-full sm:w-56"
            />

            <x-text-input wire:model.live="period" type="month" class="w-full sm:w-44" />

            <x-select-input wire:model.live="statusFilter" class="w-full sm:w-36">
                <option value="">All statuses</option>
                <option value="due">Due</option>
                <option value="partial">Partially Paid</option>
                <option value="paid">Paid</option>
            </x-select-input>
        </div>
    </div>

    @if ($rows->isEmpty())
        <x-empty-state
            title="No staff to show"
            message="Add an active staff member to start tracking their salary."
        >
            @can('employees.create')
                <x-slot:action>
                    <x-primary-button :href="route('employees.create')" wire:navigate>
                        New Staff
                    </x-primary-button>
                </x-slot:action>
            @endcan
        </x-empty-state>
    @else
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <table class="w-full min-w-[640px] text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                        <th class="px-4 py-2">Name</th>
                        <th class="px-4 py-2">Position</th>
                        <th class="px-4 py-2 text-right">Expected</th>
                        <th class="px-4 py-2 text-right">Paid</th>
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2 text-right">Balance</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-b border-slate-100 last:border-0 dark:border-slate-700/50">
                            <td class="px-4 py-3">
                                <a href="{{ route('employees.show', $row->employee) }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                                    {{ $row->employee->name }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-400">{{ $row->employee->position ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-medium text-slate-800 dark:text-slate-200">{{ number_format($row->expected, 2) }}</td>
                            <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">{{ number_format($row->paid, 2) }}</td>
                            <td class="px-4 py-3">
                                <x-badge color="{{ match ($row->status) { 'paid' => 'green', 'partial' => 'brand', default => 'amber' } }}">
                                    {{ match ($row->status) { 'paid' => 'Paid', 'partial' => 'Partially Paid', default => 'Due' } }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-3 text-right font-medium {{ $row->balance > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-400 dark:text-slate-500' }}">
                                {{ number_format($row->balance, 2) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @can('salary_payments.create')
                                    @if ($row->status !== 'paid')
                                        <a href="{{ route('employees.show', ['employee' => $row->employee, 'month' => $period->format('Y-m')]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                                            Record Payment
                                        </a>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-slate-50 dark:bg-slate-900/50">
                        <td colspan="2" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400">Total</td>
                        <td class="px-4 py-2 text-right text-sm font-medium text-slate-600 dark:text-slate-400">{{ number_format($totalExpected, 2) }}</td>
                        <td class="px-4 py-2 text-right text-sm font-medium text-slate-600 dark:text-slate-400">{{ number_format($totalPaid, 2) }}</td>
                        <td></td>
                        <td class="px-4 py-3 text-right text-base font-bold {{ $totalOutstanding > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">
                            {{ number_format($totalOutstanding, 2) }}
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{ $rows->links('pagination::simple-tailwind') }}
    @endif
</div>
