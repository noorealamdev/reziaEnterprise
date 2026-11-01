<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $company_id = null;

    public ?int $service_category_id = null;

    public ?int $tiffin_department_id = null;

    public string $period = '';

    public string $invoice_number = '';

    public ?string $vatRate = null;

    public string $periodType = 'month';

    public string $billFrom = '';

    public string $billTo = '';

    public function mount(): void
    {
        $this->company_id = request()->integer('company') ?: null;
        $this->service_category_id = request()->integer('category') ?: null;

        $period = request()->string('period')->toString();
        if (preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->period = $period;
        }

        $this->startProgressBillingCycle();
    }

    public function updatedCompanyId(): void
    {
        $this->service_category_id = null;
        $this->tiffin_department_id = null;
        $this->period = '';
        $this->startProgressBillingCycle();
    }

    public function updatedServiceCategoryId(): void
    {
        $this->tiffin_department_id = null;
        $this->period = '';
        $this->startProgressBillingCycle();
    }

    public function updatedTiffinDepartmentId(): void
    {
        $this->period = '';
        $this->startProgressBillingCycle();
    }

    /**
     * Progress Apparels bills from the 25th to the 25th rather than by
     * calendar month — like Simba's units, a hardcoded exception for this one
     * company rather than a generic feature. Opens on the cycle holding the
     * oldest unbilled entry, so bills are raised in order, and every date
     * stays editable for a bill that runs differently.
     */
    private function startProgressBillingCycle(): void
    {
        $this->periodType = 'month';
        $this->billFrom = '';
        $this->billTo = '';

        if (! $this->isProgressApparels() || ! $this->service_category_id) {
            return;
        }

        $this->periodType = 'custom';

        $oldestUnbilledDate = JobEntry::where('company_id', $this->company_id)
            ->where('service_category_id', $this->service_category_id)
            ->when($this->tiffin_department_id, fn ($query, $departmentId) => $query->where('tiffin_department_id', $departmentId))
            ->when($this->selectedMonth(), fn ($query, $month) => $query
                ->whereDate('entry_date', '>=', $month[0]->toDateString())
                ->whereDate('entry_date', '<=', $month[1]->toDateString()))
            ->whereNull('invoice_id')
            ->min('entry_date');

        $date = Carbon::parse($oldestUnbilledDate ?? now())->startOfDay();
        $cycleEnd = $date->day <= 25 ? $date->copy()->day(25) : $date->copy()->startOfMonth()->addMonthNoOverflow()->day(25);

        $this->billFrom = $cycleEnd->copy()->startOfMonth()->subMonthNoOverflow()->day(25)->toDateString();
        $this->billTo = $cycleEnd->toDateString();
    }

    private function isProgressApparels(): bool
    {
        return $this->company_id && Company::whereKey($this->company_id)->value('code') === 'PAL';
    }

    private function usesCustomDates(): bool
    {
        return $this->periodType === 'custom' && $this->isProgressApparels();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function selectedMonth(): ?array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->period)) {
            return null;
        }

        $start = Carbon::createFromFormat('Y-m-d', "{$this->period}-01")->startOfDay();

        return [$start, $start->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * The dates this bill covers, or null while they aren't a valid range.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function selectedDates(): ?array
    {
        if (! $this->usesCustomDates()) {
            return $this->selectedMonth();
        }

        $datesAreValid = Validator::make(
            ['from' => $this->billFrom, 'to' => $this->billTo],
            ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']],
        )->passes();

        return $datesAreValid
            ? [Carbon::parse($this->billFrom)->startOfDay(), Carbon::parse($this->billTo)->startOfDay()]
            : null;
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}  $dates
     * @return Builder<JobEntry>
     */
    private function unbilledEntriesIn(array $dates): Builder
    {
        return JobEntry::where('company_id', $this->company_id)
            ->where('service_category_id', $this->service_category_id)
            ->when($this->tiffin_department_id, fn ($query, $departmentId) => $query->where('tiffin_department_id', $departmentId))
            ->whereDate('entry_date', '>=', $dates[0]->toDateString())
            ->whereDate('entry_date', '<=', $dates[1]->toDateString())
            ->whereNull('invoice_id');
    }

    public function generate(): void
    {
        Gate::authorize('invoices.create');

        $requiresDepartment = $this->tiffinDepartmentRequired();
        $usesCustomDates = $this->usesCustomDates();

        $validated = $this->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'service_category_id' => ['required', 'integer', 'exists:service_categories,id'],
            // Factories want Swing and Wash Worker billed separately, not
            // combined into one Tiffin invoice — so a department is picked
            // up front here, same as it's required on the Job Entry itself.
            // Only required once the company actually has departments
            // configured — a company with none still has plain, department-
            // less Tiffin entries, and those must stay invoiceable exactly
            // as before.
            'tiffin_department_id' => [
                Rule::requiredIf($requiresDepartment),
                'nullable',
                'integer',
                Rule::exists('company_tiffin_departments', 'tiffin_department_id')->where('company_id', $this->company_id),
            ],
            'period' => $usesCustomDates ? ['nullable'] : ['required', 'date_format:Y-m'],
            'billFrom' => $usesCustomDates ? ['required', 'date_format:Y-m-d'] : ['nullable'],
            'billTo' => $usesCustomDates ? ['required', 'date_format:Y-m-d', 'after_or_equal:billFrom'] : ['nullable'],
            'invoice_number' => ['required', 'string', 'max:255', Rule::unique('invoices', 'invoice_number')],
            'vatRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [], ['billFrom' => 'From date', 'billTo' => 'To date']);

        [$start, $end] = $dates = $this->selectedDates();

        $entries = $this->unbilledEntriesIn($dates)->get();

        if ($entries->isEmpty()) {
            $usesCustomDates
                ? $this->addError('billFrom', 'There are no unbilled job entries for this company and category in these dates.')
                : $this->addError('period', 'There are no unbilled job entries for this company and category in the selected month.');

            return;
        }

        $company = Company::findOrFail($this->company_id);
        $category = ServiceCategory::findOrFail($this->service_category_id);
        $invoiceNumber = trim($validated['invoice_number']);

        $invoice = DB::transaction(function () use ($company, $category, $start, $end, $invoiceNumber, $entries) {
            $invoice = Invoice::create([
                'company_id' => $company->id,
                'service_category_id' => $category->id,
                'invoice_number' => $invoiceNumber,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'status' => 'due',
                'vat_percent' => $this->vatRate !== null && trim($this->vatRate) !== '' ? (float) $this->vatRate : null,
                'created_by' => auth()->id(),
            ]);

            JobEntry::whereIn('id', $entries->pluck('id'))->update(['invoice_id' => $invoice->id]);

            return $invoice;
        });

        session()->flash('status', "Invoice {$invoice->invoice_number} created.");

        $this->redirect(route('invoices.show', $invoice), navigate: true);
    }

    /**
     * The selected company's Tiffin departments — populated for Tiffin
     * (every company wants it billed per department instead of as one
     * combined invoice), and also for Daily Basic Labour, but only for
     * Simba Fashion, which bills that category separately per unit using
     * the same department field/picker.
     */
    private function companyTiffinDepartments(): Collection
    {
        if (! $this->company_id || ! $this->service_category_id) {
            return collect();
        }

        $categoryName = ServiceCategory::find($this->service_category_id)?->name;
        $company = Company::find($this->company_id);

        $applies = $categoryName === 'Tiffin'
            || ($categoryName === 'Daily Basic Labour' && $company?->code === 'SFL');

        if (! $applies) {
            return collect();
        }

        return $company?->tiffinDepartments()->orderBy('name')->get() ?? collect();
    }

    /**
     * "Tiffin Department" for Tiffin, "Unit" for Simba's Daily Basic
     * Labour — the same underlying field, labeled for what it means in
     * each category.
     */
    private function departmentFieldLabel(): string
    {
        return ServiceCategory::find($this->service_category_id)?->name === 'Tiffin'
            ? 'Tiffin Department'
            : 'Unit';
    }

    /**
     * A department only has to be picked when the company actually has any
     * configured — a company with none still has plain, department-less
     * Tiffin entries, and those stay invoiceable exactly as before.
     */
    private function tiffinDepartmentRequired(): bool
    {
        return $this->companyTiffinDepartments()->isNotEmpty();
    }

    public function with(): array
    {
        $company = $this->company_id ? Company::find($this->company_id) : null;
        $tiffinDepartments = $this->companyTiffinDepartments();
        $requiresDepartment = $tiffinDepartments->isNotEmpty();

        $serviceCategories = $company
            ? $company->serviceCategories()->orderBy('sort_order')->get()
            : collect();

        $periodOptions = ($this->company_id && $this->service_category_id && (! $requiresDepartment || $this->tiffin_department_id))
            ? JobEntry::where('company_id', $this->company_id)
                ->where('service_category_id', $this->service_category_id)
                ->when($requiresDepartment, fn ($query) => $query->where('tiffin_department_id', $this->tiffin_department_id))
                ->get(['entry_date'])
                ->map(fn (JobEntry $entry) => $entry->entry_date->format('Y-m'))
                ->unique()
                ->sortDesc()
                ->values()
            : collect();

        $preview = null;
        $usesCustomDates = $this->usesCustomDates();
        $selectedDates = $this->selectedDates();
        $isReadyForDates = $this->company_id && $this->service_category_id && (! $requiresDepartment || $this->tiffin_department_id);

        if ($isReadyForDates && $selectedDates) {
            [$start, $end] = $selectedDates;

            $unbilledQuery = $this->unbilledEntriesIn($selectedDates);

            $preview = [
                'count' => $unbilledQuery->count(),
                'total' => $unbilledQuery->sum('bill_amount'),
                'existingInvoices' => Invoice::where('company_id', $this->company_id)
                    ->where('service_category_id', $this->service_category_id)
                    ->when(
                        $this->isProgressApparels(),
                        fn ($query) => $query->whereDate('period_start', '<=', $end->toDateString())->whereDate('period_end', '>=', $start->toDateString()),
                        fn ($query) => $query->where('period_start', $start->toDateString()),
                    )
                    ->when($requiresDepartment, fn ($query) => $query->whereHas(
                        'jobEntries',
                        fn ($entryQuery) => $entryQuery->where('tiffin_department_id', $this->tiffin_department_id)
                    ))
                    ->get(),
            ];
        }

        return [
            'companies' => Company::orderBy('name')->get(),
            'serviceCategories' => $serviceCategories,
            'tiffinDepartments' => $tiffinDepartments,
            'departmentFieldLabel' => $this->departmentFieldLabel(),
            'periodOptions' => $periodOptions,
            'preview' => $preview,
            'isProgressApparels' => $this->isProgressApparels(),
            'usesCustomDates' => $usesCustomDates,
            'isReadyForDates' => $isReadyForDates,
            'datesAreInvalid' => $usesCustomDates && $isReadyForDates && ! $selectedDates,
        ];
    }
}; ?>

<form wire:submit="generate" class="space-y-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-800">
    <div>
        <x-input-label for="company_id" value="Company" />
        <x-select-input wire:model.live="company_id" id="company_id" class="mt-1 block w-full" required>
            <option value="">Select a company…</option>
            @foreach ($companies as $company)
                <option value="{{ $company->id }}">{{ $company->name }}</option>
            @endforeach
        </x-select-input>
        <x-input-error :messages="$errors->get('company_id')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="service_category_id" value="Service Category" />
        <x-select-input wire:model.live="service_category_id" id="service_category_id" class="mt-1 block w-full" required :disabled="! $company_id">
            <option value="">
                {{ $company_id ? 'Select a category…' : 'Select a company first' }}
            </option>
            @foreach ($serviceCategories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </x-select-input>
        @if ($company_id && $serviceCategories->isEmpty())
            <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">This company isn't subscribed to any service categories yet.</p>
        @endif
        <x-input-error :messages="$errors->get('service_category_id')" class="mt-2" />
    </div>

    @if ($tiffinDepartments->isNotEmpty())
        <div>
            <x-input-label for="tiffin_department_id" :value="$departmentFieldLabel" />
            <x-select-input wire:model.live="tiffin_department_id" id="tiffin_department_id" class="mt-1 block w-full" required>
                <option value="">Select {{ $departmentFieldLabel === 'Unit' ? 'a unit' : 'a department' }}…</option>
                @foreach ($tiffinDepartments as $department)
                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                @endforeach
            </x-select-input>
            <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">
                @if ($departmentFieldLabel === 'Unit')
                    Simba Fashion bills Daily Basic Labour separately per unit — each unit gets its own
                    invoice, never combined into one.
                @else
                    Factories want each Tiffin department billed separately — Swing and Wash Worker each get
                    their own invoice, never combined into one.
                @endif
            </p>
            <x-input-error :messages="$errors->get('tiffin_department_id')" class="mt-2" />
        </div>
    @endif

    @if ($isProgressApparels && $service_category_id)
        <div class="inline-flex flex-wrap gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-900/50">
            @foreach (['custom' => 'Custom Dates', 'month' => 'Whole Month'] as $type => $label)
                <button
                    type="button"
                    wire:click="$set('periodType', '{{ $type }}')"
                    aria-pressed="{{ $periodType === $type ? 'true' : 'false' }}"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $periodType === $type ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    @endif

    @if ($usesCustomDates)
        <div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="bill_from" value="From" />
                    <x-text-input wire:model.live="billFrom" id="bill_from" type="date" class="mt-1 block w-full" required :disabled="! $isReadyForDates" />
                    <x-input-error :messages="$errors->get('billFrom')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="bill_to" value="To" />
                    <x-text-input wire:model.live="billTo" id="bill_to" type="date" class="mt-1 block w-full" required :disabled="! $isReadyForDates" />
                    <x-input-error :messages="$errors->get('billTo')" class="mt-2" />
                </div>
            </div>
            @if (! $isReadyForDates)
                <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">Select {{ $departmentFieldLabel === 'Unit' ? 'a unit' : 'a department' }} first.</p>
            @elseif ($datesAreInvalid)
                <p class="mt-2 text-sm text-red-600 dark:text-red-400">Choose a From and To date. To can't be before From.</p>
            @else
                <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">
                    Progress Apparels bills run from the 25th to the 25th. Change the dates if this bill is different.
                    An entry dated on the 25th is billed once, on whichever bill is generated first.
                </p>
            @endif
        </div>
    @else
        <div>
            <x-input-label for="period" value="Month" />
            <x-select-input wire:model.live="period" id="period" class="mt-1 block w-full" required :disabled="! $service_category_id || ($tiffinDepartments->isNotEmpty() && ! $tiffin_department_id)">
                <option value="">
                    @if (! $service_category_id)
                        Select a category first
                    @elseif ($tiffinDepartments->isNotEmpty() && ! $tiffin_department_id)
                        Select {{ $departmentFieldLabel === 'Unit' ? 'a unit' : 'a department' }} first
                    @else
                        Select a month…
                    @endif
                </option>
                @foreach ($periodOptions as $option)
                    <option value="{{ $option }}">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', "{$option}-01")->format('F Y') }}</option>
                @endforeach
            </x-select-input>
            @if ($service_category_id && ($tiffinDepartments->isEmpty() || $tiffin_department_id) && $periodOptions->isEmpty())
                <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">This company has no entries in this category yet.</p>
            @endif
            <x-input-error :messages="$errors->get('period')" class="mt-2" />
        </div>
    @endif

    <div>
        <x-input-label for="invoice_number" value="Invoice Number" />
        <x-text-input wire:model="invoice_number" id="invoice_number" placeholder="e.g. RE/AAL/L-U/#240/082026" class="mt-1 block w-full" required />
        <x-input-error :messages="$errors->get('invoice_number')" class="mt-2" />
    </div>

    @if ($preview)
        @if ($preview['existingInvoices']->isNotEmpty())
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                <p class="font-medium">
                    {{ $preview['existingInvoices']->count() }} {{ Str::plural('invoice', $preview['existingInvoices']->count()) }} already
                    {{ $preview['existingInvoices']->count() === 1 ? 'exists' : 'exist' }} for this company/category in {{ $usesCustomDates ? 'these dates' : 'this month' }}:
                </p>
                <ul class="mt-1 list-inside list-disc">
                    @foreach ($preview['existingInvoices'] as $existing)
                        <li>
                            <a href="{{ route('invoices.show', $existing) }}" wire:navigate class="underline">{{ $existing->invoice_number }}</a>
                            — {{ ucfirst($existing->status) }}
                        </li>
                    @endforeach
                </ul>
                <p class="mt-1">Generating again will only include entries not yet billed.</p>
            </div>
        @endif

        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900/50">
            @if ($preview['count'] > 0)
                <p class="text-sm text-slate-600 dark:text-slate-400">
                    <span class="font-semibold text-slate-900 dark:text-white">{{ $preview['count'] }}</span>
                    unbilled {{ Str::plural('entry', $preview['count']) }} found, totalling
                    <span class="font-semibold text-slate-900 dark:text-white">{{ number_format($preview['total'], 2) }}</span>.
                </p>
            @else
                <p class="text-sm text-slate-500 dark:text-slate-400">No unbilled entries found for this company/category in {{ $usesCustomDates ? 'these dates' : 'this month' }}.</p>
            @endif
        </div>
    @endif

    <div>
        <x-input-label for="vatRate" value="VAT Rate (%)" />
        <x-text-input wire:model="vatRate" id="vatRate" type="number" step="0.01" min="0" max="100" placeholder="e.g. 15" class="mt-1 block w-full" />
        <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">Leave blank if this invoice has no VAT. Rates vary (7%, 10%, 15%, ...), so enter whatever applies here.</p>
        <x-input-error :messages="$errors->get('vatRate')" class="mt-2" />
    </div>

    <div class="flex items-center justify-end gap-3">
        <x-secondary-button :href="route('bill-statement.index', $company_id ? ['company' => $company_id] : [])" wire:navigate>
            Cancel
        </x-secondary-button>
        <x-primary-button>
            Generate Invoice
        </x-primary-button>
    </div>
</form>
