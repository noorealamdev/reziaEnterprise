<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\JobEntry;
use App\Models\ServiceCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $company_id = null;

    public ?int $service_category_id = null;

    public string $period = '';

    public string $invoice_number = '';

    public ?string $vatRate = null;

    public function mount(): void
    {
        $this->company_id = request()->integer('company') ?: null;
        $this->service_category_id = request()->integer('category') ?: null;

        $period = request()->string('period')->toString();
        if (preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->period = $period;
        }
    }

    public function updatedCompanyId(): void
    {
        $this->service_category_id = null;
        $this->period = '';
    }

    public function updatedServiceCategoryId(): void
    {
        $this->period = '';
    }

    public function generate(): void
    {
        Gate::authorize('invoices.create');

        $validated = $this->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'service_category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'period' => ['required', 'date_format:Y-m'],
            'invoice_number' => ['required', 'string', 'max:255', Rule::unique('invoices', 'invoice_number')],
            'vatRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $start = Carbon::createFromFormat('Y-m', $this->period)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $entries = JobEntry::where('company_id', $this->company_id)
            ->where('service_category_id', $this->service_category_id)
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->whereNull('invoice_id')
            ->get();

        if ($entries->isEmpty()) {
            $this->addError('period', 'There are no unbilled job entries for this company and category in the selected month.');

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

    public function with(): array
    {
        $company = $this->company_id ? Company::find($this->company_id) : null;

        $serviceCategories = $company
            ? $company->serviceCategories()->orderBy('sort_order')->get()
            : collect();

        $periodOptions = ($this->company_id && $this->service_category_id)
            ? JobEntry::where('company_id', $this->company_id)
                ->where('service_category_id', $this->service_category_id)
                ->get(['entry_date'])
                ->map(fn (JobEntry $entry) => $entry->entry_date->format('Y-m'))
                ->unique()
                ->sortDesc()
                ->values()
            : collect();

        $preview = null;

        if ($this->company_id && $this->service_category_id && preg_match('/^\d{4}-\d{2}$/', $this->period)) {
            $start = Carbon::createFromFormat('Y-m', $this->period)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            $unbilledQuery = JobEntry::where('company_id', $this->company_id)
                ->where('service_category_id', $this->service_category_id)
                ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
                ->whereNull('invoice_id');

            $preview = [
                'count' => $unbilledQuery->count(),
                'total' => $unbilledQuery->sum('bill_amount'),
                'existingInvoices' => Invoice::where('company_id', $this->company_id)
                    ->where('service_category_id', $this->service_category_id)
                    ->where('period_start', $start->toDateString())
                    ->get(),
            ];
        }

        return [
            'companies' => Company::orderBy('name')->get(),
            'serviceCategories' => $serviceCategories,
            'periodOptions' => $periodOptions,
            'preview' => $preview,
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

    <div>
        <x-input-label for="period" value="Month" />
        <x-select-input wire:model.live="period" id="period" class="mt-1 block w-full" required :disabled="! $service_category_id">
            <option value="">
                {{ $service_category_id ? 'Select a month…' : 'Select a category first' }}
            </option>
            @foreach ($periodOptions as $option)
                <option value="{{ $option }}">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $option)->format('F Y') }}</option>
            @endforeach
        </x-select-input>
        @if ($service_category_id && $periodOptions->isEmpty())
            <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">This company has no entries in this category yet.</p>
        @endif
        <x-input-error :messages="$errors->get('period')" class="mt-2" />
    </div>

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
                    {{ $preview['existingInvoices']->count() === 1 ? 'exists' : 'exist' }} for this company/category in this month:
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
                <p class="text-sm text-slate-500 dark:text-slate-400">No unbilled entries found for this company/category in this month.</p>
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
