<?php

use App\Models\Company;
use App\Models\ServiceCategory;
use App\Models\TiffinDepartment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public ?Company $company = null;

    public string $name = '';

    public string $code = '';

    public ?string $address = null;

    public ?string $contact_person = null;

    public ?string $phone = null;

    public ?string $email = null;

    public ?string $bepza_reg_no = null;

    public bool $is_active = true;

    /** @var array<int, int> */
    public array $serviceCategoryIds = [];

    /** @var array<int, int> */
    public array $tiffinDepartmentIds = [];

    public ?int $tiffinCategoryId = null;

    public ?string $tiffin_bill_rate = null;

    public function mount(?Company $company = null): void
    {
        // Laravel's container auto-instantiates an empty Company() when no
        // `company` prop is passed (it has a no-arg constructor), rather than
        // leaving $company as null — so check `exists`, not truthiness.
        $this->company = ($company && $company->exists) ? $company : null;
        $this->tiffinCategoryId = ServiceCategory::where('name', 'Tiffin')->value('id');

        if ($this->company) {
            $company = $this->company;
            $this->name = $company->name;
            $this->code = $company->code;
            $this->address = $company->address;
            $this->contact_person = $company->contact_person;
            $this->phone = $company->phone;
            $this->email = $company->email;
            $this->bepza_reg_no = $company->bepza_reg_no;
            $this->is_active = $company->is_active;
            $this->tiffin_bill_rate = $company->tiffin_bill_rate !== null ? (string) $company->tiffin_bill_rate : null;
            // Cast to strings to match what a toggled checkbox produces —
            // Alpine's x-show below compares against a string, and without
            // this an id seeded here from Eloquent (an int) would silently
            // fail categories.includes('...') on first render, hiding the
            // Tiffin Departments/Bill Rate section even when Tiffin is
            // already checked.
            $this->serviceCategoryIds = $company->serviceCategories()->pluck('service_categories.id')->map(fn ($id) => (string) $id)->all();
            $this->tiffinDepartmentIds = $company->tiffinDepartments()->pluck('tiffin_departments.id')->all();
        }
    }

    public function updatedCode(string $value): void
    {
        $this->code = strtoupper($value);
    }

    public function save(): void
    {
        Gate::authorize($this->company ? 'companies.modify' : 'companies.create');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('companies', 'code')->ignore($this->company?->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'bepza_reg_no' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'tiffin_bill_rate' => ['nullable', 'numeric', 'min:0'],
            'serviceCategoryIds' => ['array'],
            'serviceCategoryIds.*' => ['exists:service_categories,id'],
            'tiffinDepartmentIds' => ['array'],
            'tiffinDepartmentIds.*' => ['exists:tiffin_departments,id'],
        ]);

        $company = $this->company ?? new Company;
        $company->fill(collect($validated)->except(['serviceCategoryIds', 'tiffinDepartmentIds'])->all());
        $company->save();

        $company->serviceCategories()->sync(array_map('intval', $validated['serviceCategoryIds']));
        $company->tiffinDepartments()->sync(array_map('intval', $validated['tiffinDepartmentIds']));

        session()->flash('status', $this->company ? 'Company updated.' : 'Company created.');

        $this->redirect(route('companies.index'), navigate: true);
    }

    public function with(): array
    {
        return [
            'serviceCategories' => ServiceCategory::orderBy('sort_order')->get(),
            'tiffinDepartments' => TiffinDepartment::orderBy('name')->get(),
        ];
    }
}; ?>

<form wire:submit="save" class="space-y-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-800">
    <div>
        <x-input-label for="name" value="Company Name" />
        <x-text-input wire:model="name" id="name" placeholder="e.g. Ananta Apparels Ltd" class="mt-1 block w-full" required autofocus />
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="code" value="Company Code" />
        <x-text-input wire:model="code" id="code" placeholder="e.g. AAL" class="mt-1 block w-full" required />
        <x-input-error :messages="$errors->get('code')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="address" value="Address" />
        <x-text-input wire:model="address" id="address" placeholder="e.g. Zone Road 5, AEPZ, Adamjee, Narayangonj" class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get('address')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="contact_person" value="Contact Person" />
        <x-text-input wire:model="contact_person" id="contact_person" placeholder="e.g. Mr. Karim Rahman" class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get('contact_person')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="phone" value="Phone" />
        <x-text-input wire:model="phone" id="phone" placeholder="e.g. 01712345678" class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get('phone')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="email" value="Email" />
        <x-text-input wire:model="email" id="email" type="email" placeholder="e.g. accounts@company.com" class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get('email')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="bepza_reg_no" value="BEPZA Registration No." />
        <x-text-input wire:model="bepza_reg_no" id="bepza_reg_no" placeholder="e.g. BEPZA-1234" class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get('bepza_reg_no')" class="mt-2" />
    </div>

    <div class="flex items-center gap-2">
        <input type="checkbox" wire:model="is_active" id="is_active" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
        <x-input-label for="is_active" value="Active" class="!mb-0" />
    </div>

    <div>
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Service Categories</h3>
            <a href="{{ route('service-categories.index') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                Manage categories
            </a>
        </div>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Which services does this company subscribe to?</p>

        <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
            @foreach ($serviceCategories as $category)
                <x-checkbox-list-item
                    wire:model="serviceCategoryIds"
                    :value="$category->id"
                    :label="$category->name"
                    :hint="$category->invoice_code"
                />
            @endforeach
        </div>
    </div>

    @if ($tiffinCategoryId)
        <div x-data="{ categories: @entangle('serviceCategoryIds') }" x-show="categories.includes('{{ $tiffinCategoryId }}')" x-cloak class="space-y-6">
            <div>
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Tiffin Departments</h3>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Only relevant while Tiffin is selected above.</p>

                <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($tiffinDepartments as $department)
                        <x-checkbox-list-item
                            wire:model="tiffinDepartmentIds"
                            :value="$department->id"
                            :label="$department->name"
                        />
                    @endforeach
                </div>
            </div>

            <div>
                <x-input-label for="tiffin_bill_rate" value="Tiffin Bill Rate (per person)" />
                <x-text-input wire:model="tiffin_bill_rate" id="tiffin_bill_rate" type="number" step="0.01" min="0" placeholder="e.g. 30.00" class="mt-1 block w-full" />
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    The whole Tiffin meal bills at this fixed rate per person — set once here instead of typing it on every entry.
                </p>
                <x-input-error :messages="$errors->get('tiffin_bill_rate')" class="mt-2" />
            </div>
        </div>
    @endif

    <div class="flex items-center justify-end gap-3">
        <x-secondary-button :href="route('companies.index')" wire:navigate>
            Cancel
        </x-secondary-button>
        <x-primary-button>
            {{ $company ? 'Save Changes' : 'Create Company' }}
        </x-primary-button>
    </div>
</form>
