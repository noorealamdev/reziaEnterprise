<?php

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $company_id = null;

    public ?int $service_category_id = null;

    public string $invoice_number = '';

    public string $bill_date = '';

    public ?string $manual_amount = null;

    public ?string $manual_description = null;

    public ?string $vatRate = null;

    public ?string $remarks = null;

    public function mount(): void
    {
        $this->company_id = request()->integer('company') ?: null;
        $this->bill_date = now()->toDateString();
    }

    public function updatedCompanyId(): void
    {
        $this->service_category_id = null;
    }

    public function save(): void
    {
        Gate::authorize('invoices.create');

        $validated = $this->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'service_category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'invoice_number' => ['required', 'string', 'max:255', Rule::unique('invoices', 'invoice_number')],
            'bill_date' => ['required', 'date'],
            'manual_amount' => ['required', 'numeric', 'min:0.01'],
            'manual_description' => ['nullable', 'string', 'max:255'],
            'vatRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $invoice = Invoice::create([
            'company_id' => $validated['company_id'],
            'service_category_id' => $validated['service_category_id'],
            'invoice_number' => trim($validated['invoice_number']),
            'period_start' => $validated['bill_date'],
            'period_end' => $validated['bill_date'],
            'status' => 'due',
            'vat_percent' => $validated['vatRate'] !== null && trim((string) $validated['vatRate']) !== '' ? (float) $validated['vatRate'] : null,
            'manual_amount' => $validated['manual_amount'],
            'manual_description' => $validated['manual_description'] ? trim($validated['manual_description']) : null,
            'remarks' => $validated['remarks'] ? trim($validated['remarks']) : null,
            'created_by' => auth()->id(),
        ]);

        session()->flash('status', "Invoice {$invoice->invoice_number} added.");

        $this->redirect(route('invoices.show', $invoice), navigate: true);
    }

    public function with(): array
    {
        $company = $this->company_id ? Company::find($this->company_id) : null;

        return [
            'companies' => Company::orderBy('name')->get(),
            'serviceCategories' => $company
                ? $company->serviceCategories()->orderBy('sort_order')->get()
                : collect(),
        ];
    }
}; ?>

<form wire:submit="save" class="space-y-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-800">
    <p class="text-sm text-slate-500 dark:text-slate-400">
        For a bill that predates this system, or was issued outside the normal flow — typed in directly rather
        than built from job entries. Once saved, use the invoice's own page to record a payment against it or
        attach a scan of the original bill.
    </p>

    <div>
        <x-input-label for="manual_company_id" value="Company" />
        <x-select-input wire:model.live="company_id" id="manual_company_id" class="mt-1 block w-full" required>
            <option value="">Select a company…</option>
            @foreach ($companies as $company)
                <option value="{{ $company->id }}">{{ $company->name }}</option>
            @endforeach
        </x-select-input>
        <x-input-error :messages="$errors->get('company_id')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="manual_service_category_id" value="Service Category" />
        <x-select-input wire:model="service_category_id" id="manual_service_category_id" class="mt-1 block w-full" required :disabled="! $company_id">
            <option value="">
                {{ $company_id ? 'Select a category…' : 'Select a company first' }}
            </option>
            @foreach ($serviceCategories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </x-select-input>
        <x-input-error :messages="$errors->get('service_category_id')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="invoice_number" value="Invoice Number" />
        <x-text-input wire:model="invoice_number" id="invoice_number" placeholder="e.g. AAL-DiBL-202601" class="mt-1 block w-full" required />
        <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">Whatever number was on the original bill.</p>
        <x-input-error :messages="$errors->get('invoice_number')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="bill_date" value="Bill Date" />
        <x-text-input wire:model="bill_date" id="bill_date" type="date" class="mt-1 block w-full" required />
        <x-input-error :messages="$errors->get('bill_date')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="manual_amount" value="Amount" />
        <x-text-input wire:model="manual_amount" id="manual_amount" type="number" step="0.01" min="0.01" placeholder="e.g. 50000.00" class="mt-1 block w-full" required />
        <x-input-error :messages="$errors->get('manual_amount')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="manual_description" value="Description" />
        <x-text-input wire:model="manual_description" id="manual_description" placeholder="e.g. Diesel supply, Jan–Mar 2026" class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get('manual_description')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="manual_vatRate" value="VAT Rate (%)" />
        <x-text-input wire:model="vatRate" id="manual_vatRate" type="number" step="0.01" min="0" max="100" placeholder="e.g. 15" class="mt-1 block w-full" />
        <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">Leave blank if this bill has no VAT.</p>
        <x-input-error :messages="$errors->get('vatRate')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="manual_remarks" value="Remarks" />
        <x-textarea-input wire:model="remarks" id="manual_remarks" placeholder="Any other detail about this bill" class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get('remarks')" class="mt-2" />
    </div>

    <div class="flex items-center justify-end gap-3">
        <x-secondary-button :href="route('bill-statement.index', $company_id ? ['company' => $company_id] : [])" wire:navigate>
            Cancel
        </x-secondary-button>
        <x-primary-button>
            Add Past Invoice
        </x-primary-button>
    </div>
</form>
