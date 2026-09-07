<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\ServiceCategory;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    /** Rows per screen page — print always shows every filtered row regardless of this. */
    private const PER_PAGE = 15;

    #[Url(as: 'company', history: true)]
    public string $companyFilter = '';

    #[Url(as: 'category', history: true)]
    public string $categoryFilter = '';

    #[Url(as: 'year', history: true)]
    public string $yearFilter = '';

    #[Url(as: 'month', history: true)]
    public string $monthFilter = '';

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
    }

    public function updatingCompanyFilter(): void
    {
        $this->resetPage();
    }

    public function updatingCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingYearFilter(): void
    {
        // A month only makes sense within a chosen year — clear it if the
        // year changes so the two never disagree.
        $this->monthFilter = '';
        $this->resetPage();
    }

    public function updatingMonthFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
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
            'company' => $this->companyFilter,
            'category' => $this->categoryFilter,
            'year' => $this->yearFilter,
            'month' => $this->monthFilter,
            'status' => $this->statusFilter,
            'q' => $this->search,
        ], fn ($value) => $value !== '');
    }

    public function with(): array
    {
        $companies = Company::orderBy('name')->get();
        $categories = ServiceCategory::orderBy('sort_order')->get();

        $invoiceRows = Invoice::query()
            ->with(['company', 'serviceCategory'])
            ->withSum('jobEntries as amount', 'bill_amount')
            ->withSum('jobEntries as advancePaid', 'company_adv_payment')
            ->withSum('payments as paidViaPayments', 'amount')
            ->when($this->companyFilter, fn ($query) => $query->where('company_id', $this->companyFilter))
            ->when($this->categoryFilter, fn ($query) => $query->where('service_category_id', $this->categoryFilter))
            ->get()
            ->map(function (Invoice $invoice) {
                $amount = (float) ($invoice->manual_amount ?? $invoice->amount);
                $vatAmount = $invoice->vat_percent ? round($amount * (float) $invoice->vat_percent / 100, 2) : 0;
                $advancePaid = (float) $invoice->advancePaid;
                $paidViaPayments = (float) $invoice->paidViaPayments;

                return (object) [
                    'company' => $invoice->company,
                    'category' => $invoice->serviceCategory,
                    'period_start' => $invoice->period_start,
                    'amount' => $amount + $vatAmount,
                    'status' => $invoice->status,
                    'invoice' => $invoice,
                    // Real money received so far — an advance paid before
                    // the invoice was even generated (e.g. ETP Eid
                    // Holiday's) is just as real as a payment recorded
                    // afterward, and both need to count here so Total Paid
                    // + Total Outstanding always reconciles to Total Billed.
                    'paidAmount' => $advancePaid + $paidViaPayments,
                    'balanceDue' => max(0, $amount + $vatAmount - $advancePaid - $paidViaPayments),
                    // A signed copy on file is proof the bill was both sent
                    // to the factory and handed back signed — there's no
                    // separate "sent" flag to track, since the two always
                    // happen together in practice.
                    'hasSignedCopy' => $invoice->signed_copy_path !== null,
                ];
            });

        // A month's worth of unbilled entries is still a bill the factory
        // owes — it just hasn't had a formal invoice document generated
        // for it yet. Show it here too so the statement reflects every
        // month of activity, not only the ones someone remembered to
        // click "Generate Invoice" for. Grouped by category too, since
        // invoices are now generated per category — a company can have
        // several concurrent pending bills the same month.
        $pendingRows = JobEntry::query()
            ->whereNull('invoice_id')
            ->with(['company', 'serviceCategory'])
            ->when($this->companyFilter, fn ($query) => $query->where('company_id', $this->companyFilter))
            ->when($this->categoryFilter, fn ($query) => $query->where('service_category_id', $this->categoryFilter))
            ->get()
            ->groupBy(fn (JobEntry $entry) => $entry->company_id.'-'.$entry->service_category_id.'-'.$entry->entry_date->format('Y-m'))
            ->map(function ($entries) {
                $first = $entries->first();
                $amount = (float) $entries->sum('bill_amount');
                $advancePaid = (float) $entries->sum('company_adv_payment');

                return (object) [
                    'company' => $first->company,
                    'category' => $first->serviceCategory,
                    'period_start' => $first->entry_date->copy()->startOfMonth(),
                    'amount' => $amount,
                    'status' => 'pending',
                    'invoice' => null,
                    'paidAmount' => $advancePaid,
                    'balanceDue' => max(0, $amount - $advancePaid),
                    // Not yet invoiced — can't have been sent or signed.
                    'hasSignedCopy' => false,
                ];
            })
            ->values();

        // Most recent period first — this is a live operating statement,
        // not an archive, so the current month's activity should be the
        // first thing visible instead of buried behind a full history of
        // older, already-settled periods. Chained stable sorts (PHP 8's
        // sort is stable) applied least-significant-first, so the final
        // sortByDesc on period_start wins as the primary key while company
        // and category stay alphabetical as tie-breakers within a period.
        $allRows = $invoiceRows->concat($pendingRows)
            ->sortBy(fn ($row) => $row->category->name)
            ->sortBy(fn ($row) => $row->company->name)
            ->sortByDesc(fn ($row) => $row->period_start)
            ->values();

        // Years offered in the filter always reflect what's actually there
        // (scoped to the company filter, if one's set) — never a hardcoded
        // range — and stay stable regardless of the year/month/status
        // filters themselves, so picking a year never removes other years
        // from the dropdown.
        $availableYears = $allRows->map(fn ($row) => $row->period_start->year)->unique()->sortDesc()->values();

        // Search only ever matches invoiced rows (a pending row has no
        // invoice number yet to search by) — a substring, case-insensitive
        // match on the invoice number, which is the only invoice identifier
        // ever shown to a user anywhere in this app (the numeric primary
        // key is never displayed, so it isn't something anyone could type).
        $searchTerm = strtolower(trim($this->search));

        $rows = $allRows
            ->when($this->yearFilter, fn ($rows) => $rows->filter(fn ($row) => $row->period_start->year == $this->yearFilter))
            ->when($this->monthFilter, fn ($rows) => $rows->filter(fn ($row) => $row->period_start->month == $this->monthFilter))
            ->when($this->statusFilter === 'billed', fn ($rows) => $rows->filter(fn ($row) => $row->invoice !== null))
            ->when($this->statusFilter === 'unbilled', fn ($rows) => $rows->filter(fn ($row) => $row->invoice === null))
            ->when($this->statusFilter === 'paid', fn ($rows) => $rows->filter(fn ($row) => $row->status === 'paid'))
            ->when($this->statusFilter === 'unpaid', fn ($rows) => $rows->filter(fn ($row) => in_array($row->status, ['due', 'partial'], true)))
            ->when($this->statusFilter === 'signed', fn ($rows) => $rows->filter(fn ($row) => $row->hasSignedCopy))
            ->when($searchTerm !== '', fn ($rows) => $rows->filter(fn ($row) => $row->invoice !== null
                && str_contains(strtolower($row->invoice->invoice_number), $searchTerm)))
            ->values();

        $totalBilled = (float) $rows->sum('amount');
        // Actual money received, not the full amount of every invoice
        // marked Paid — a Partially Paid invoice has received some of its
        // amount, and that still counts, as does an advance paid before an
        // invoice even existed (e.g. ETP Eid Holiday). Both are added into
        // each row's paidAmount, which is what keeps Total Paid + Total
        // Outstanding reconciling to Total Billed below.
        $totalPaid = (float) $rows->sum('paidAmount');

        $monthOptions = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(2000, $m, 1)->format('F')]);

        // $rows itself always holds every filtered row — the printed
        // statement must show them all on one document. On screen, only
        // one page's worth is actually visible at a time (see the
        // per-row hidden/print:!table-row toggle in the template below);
        // this paginator exists purely to drive that page number and the
        // page-link controls, not to slice the data itself.
        $pagination = (new LengthAwarePaginator(
            [],
            $rows->count(),
            self::PER_PAGE,
            $this->getPage(),
            ['pageName' => 'page', 'path' => $this->paginationPath]
        ))->appends($this->urlQueryState());

        return [
            'companies' => $companies,
            'categories' => $categories,
            'selectedCompany' => $this->companyFilter ? $companies->firstWhere('id', (int) $this->companyFilter) : null,
            'selectedCategory' => $this->categoryFilter ? $categories->firstWhere('id', (int) $this->categoryFilter) : null,
            'rows' => $rows,
            'pagination' => $pagination,
            'hasUnfilteredRows' => $allRows->isNotEmpty(),
            'availableYears' => $availableYears,
            'monthOptions' => $monthOptions,
            'totalBilled' => $totalBilled,
            'totalPaid' => $totalPaid,
            // Sum of each row's own balance due — mathematically equal to
            // Billed − Paid now that Paid includes advances, except where
            // the max(0, ...) below clamps an individual row that's been
            // overpaid rather than letting it go negative.
            'totalOutstanding' => (float) $rows->sum('balanceDue'),
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
        <div class="flex flex-wrap items-center gap-3">
            <x-text-input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Search by invoice number…"
                class="w-full sm:w-64"
            />

            <x-select-input wire:model.live="companyFilter" class="w-full sm:w-56">
                <option value="">All companies</option>
                @foreach ($companies as $company)
                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                @endforeach
            </x-select-input>

            <x-select-input wire:model.live="categoryFilter" class="w-full sm:w-48">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </x-select-input>

            <x-select-input wire:model.live="yearFilter" class="w-full sm:w-32">
                <option value="">Every year</option>
                @foreach ($availableYears as $year)
                    <option value="{{ $year }}">{{ $year }}</option>
                @endforeach
            </x-select-input>

            <x-select-input wire:model.live="monthFilter" class="w-full sm:w-40" :disabled="! $yearFilter">
                <option value="">{{ $yearFilter ? 'Every month' : 'Pick a year first' }}</option>
                @foreach ($monthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select-input>

            <x-select-input wire:model.live="statusFilter" class="w-full sm:w-36">
                <option value="">All statuses</option>
                <option value="billed">Billed</option>
                <option value="unbilled">Unbilled</option>
                <option value="paid">Paid</option>
                <option value="unpaid">Unpaid</option>
                <option value="signed">Signed</option>
            </x-select-input>
        </div>

        <div class="flex items-center gap-3">
            @if ($rows->isNotEmpty())
                <x-secondary-button type="button" onclick="window.print()">
                    Print
                </x-secondary-button>
            @endif
            <x-secondary-button :href="route('invoices.create-manual', $selectedCompany ? ['company' => $selectedCompany->id] : [])" wire:navigate>
                Add Past Invoice
            </x-secondary-button>
            @if ($selectedCompany)
                <x-primary-button :href="route('invoices.create', array_filter(['company' => $selectedCompany->id, 'category' => $selectedCategory?->id]))" wire:navigate>
                    Generate Invoice
                </x-primary-button>
            @endif
        </div>
    </div>

    @if ($rows->isEmpty())
        <x-empty-state
            :title="$hasUnfilteredRows ? 'No rows match these filters' : 'No billing activity yet'"
            :message="$hasUnfilteredRows
                ? 'Try a different year, month, status or search — or clear the filters above.'
                : ($selectedCompany
                    ? 'Log a job entry for this company to start its bill statement.'
                    : 'Once job entries are logged for any company, their bills will show up here.')"
        />
    @else
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-800 print:!mt-0 print:rounded-none print:border-0 print:p-0 print:shadow-none">
            <div class="hidden border-b-2 border-brand-700 pb-3 text-center print:block">
                <div class="flex items-center justify-center gap-3">
                    <x-application-logo class="h-10 w-10 shrink-0" />
                    <h1 class="text-2xl font-bold uppercase tracking-wide text-brand-800">{{ config('company.name') }}</h1>
                </div>
                <p class="mt-1 text-xs text-slate-600">{{ config('company.tagline') }}</p>
                <p class="mt-1 text-xs font-medium text-red-600">{{ config('company.phones') }} · E-mail: {{ config('company.email') }}</p>
                <p class="text-xs text-slate-600">{{ config('company.address') }}</p>
            </div>

            <div class="print:mt-3 print:flex print:items-end print:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white print:text-base">{{ $selectedCompany?->name ?? 'All Companies' }}</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400 print:text-xs">
                        Bill Statement
                        @if ($selectedCategory)
                            — {{ $selectedCategory->name }}
                        @endif
                    </p>
                </div>
                <p class="hidden text-xs text-slate-600 print:block">As of {{ now()->format('d-M-Y') }}</p>
            </div>

            <div class="mt-4 overflow-x-auto print:mt-3 print:overflow-visible">
                <table class="w-full min-w-[560px] text-sm print:min-w-0 print:text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400 print:border-slate-300 print:bg-slate-100 print:text-slate-600">
                            @unless ($selectedCompany)
                                <th class="px-4 py-2 print:px-2 print:py-1">Company</th>
                            @endunless
                            @unless ($selectedCategory)
                                <th class="px-4 py-2 print:px-2 print:py-1">Category</th>
                            @endunless
                            <th class="px-4 py-2 print:px-2 print:py-1">Invoice</th>
                            <th class="px-4 py-2 print:px-2 print:py-1">Period</th>
                            <th class="px-4 py-2 text-right print:px-2 print:py-1">Amount</th>
                            <th class="px-4 py-2 print:px-2 print:py-1">Status</th>
                            <th class="px-4 py-2 text-right print:px-2 print:py-1">Balance Due</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                // $rows always holds every filtered row (the
                                // printed statement must show them all on one
                                // document) — on screen, only the current
                                // page's rows stay visible; print always
                                // shows every row regardless of page, via the
                                // !important print:table-row override.
                                $rowPage = intdiv($loop->index, $pagination->perPage()) + 1;
                                $onScreenPage = $rowPage === $pagination->currentPage();
                            @endphp
                            <tr class="{{ $onScreenPage ? '' : 'hidden print:!table-row' }} border-b border-slate-100 last:border-0 dark:border-slate-700/50 print:break-inside-avoid print:border-slate-200">
                                @unless ($selectedCompany)
                                    <td class="px-4 py-3 text-slate-600 dark:text-slate-400 print:px-2 print:py-1">{{ $row->company->name }}</td>
                                @endunless
                                @unless ($selectedCategory)
                                    <td class="px-4 py-3 print:px-2 print:py-1">
                                        <x-badge color="brand" class="print:!bg-transparent print:!px-0 print:!text-slate-700">{{ $row->category->name }}</x-badge>
                                    </td>
                                @endunless
                                <td class="px-4 py-3 print:px-2 print:py-1">
                                    @if ($row->invoice)
                                        <a href="{{ route('invoices.show', $row->invoice) }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300 print:text-slate-800 print:no-underline dark:print:text-slate-800">
                                            {{ $row->invoice->invoice_number }}
                                        </a>
                                    @else
                                        <a href="{{ route('invoices.create', ['company' => $row->company->id, 'category' => $row->category->id, 'period' => $row->period_start->format('Y-m')]) }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300 print:hidden">
                                            Generate Invoice
                                        </a>
                                        <span class="hidden text-slate-400 dark:text-slate-500 print:inline">Not invoiced</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-400 print:px-2 print:py-1">{{ $row->period_start->format('F Y') }}</td>
                                <td class="px-4 py-3 text-right font-medium text-slate-800 dark:text-slate-200 print:px-2 print:py-1">{{ number_format($row->amount, 2) }}</td>
                                <td class="px-4 py-3 print:px-2 print:py-1">
                                    <div class="flex flex-wrap items-center gap-1">
                                        <x-badge color="{{ match ($row->status) { 'paid' => 'green', 'partial' => 'brand', 'due' => 'amber', default => 'slate' } }}" class="print:!bg-transparent print:!px-0 print:!text-slate-700">
                                            {{ match ($row->status) { 'pending' => 'Not Invoiced', 'partial' => 'Partially Paid', default => ucfirst($row->status) } }}
                                        </x-badge>
                                        @if ($row->hasSignedCopy && $row->status !== 'paid')
                                            <x-badge color="slate" class="print:!bg-transparent print:!px-0 print:!text-slate-700" title="Sent to the factory and signed">
                                                Signed
                                            </x-badge>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right font-medium {{ $row->balanceDue <= 0 ? 'text-slate-400 dark:text-slate-500' : 'text-red-600 dark:text-red-400' }} print:px-2 print:py-1">
                                    {{ number_format($row->balanceDue, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    @php $labelSpan = 2 + ($selectedCompany ? 0 : 1) + ($selectedCategory ? 0 : 1); @endphp
                    <tfoot>
                        <tr class="bg-slate-50 dark:bg-slate-900/50 print:break-inside-avoid print:bg-transparent">
                            <td colspan="{{ $labelSpan }}" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 print:px-2 print:py-1">Total Billed</td>
                            <td colspan="3" class="px-4 py-2 text-right text-sm font-medium text-slate-600 dark:text-slate-400 print:px-2 print:py-1">{{ number_format($totalBilled, 2) }}</td>
                        </tr>
                        <tr class="bg-slate-50 dark:bg-slate-900/50 print:break-inside-avoid print:bg-transparent">
                            <td colspan="{{ $labelSpan }}" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 print:px-2 print:py-1">Total Paid</td>
                            <td colspan="3" class="px-4 py-2 text-right text-sm font-medium text-slate-600 dark:text-slate-400 print:px-2 print:py-1">{{ number_format($totalPaid, 2) }}</td>
                        </tr>
                        <tr class="bg-slate-100 dark:bg-slate-800 print:break-inside-avoid print:border-t print:border-slate-300 print:bg-transparent">
                            <td colspan="{{ $labelSpan }}" class="px-4 py-3 text-base font-bold text-slate-900 dark:text-white print:px-2 print:py-1">Total Outstanding</td>
                            <td colspan="3" class="px-4 py-3 text-right text-base font-bold {{ $totalOutstanding > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white' }} print:px-2 print:py-1">
                                {{ number_format($totalOutstanding, 2) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="mt-4 print:hidden">
                {{ $pagination->links('pagination::simple-tailwind') }}
            </div>
        </div>
    @endif
</div>
