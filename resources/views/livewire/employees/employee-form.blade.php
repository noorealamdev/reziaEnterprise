<?php

use App\Models\Employee;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Component;

new class extends Component
{
    public ?Employee $employee = null;

    public string $name = '';

    public ?string $phone = null;

    public ?string $position = null;

    public ?string $monthly_salary = null;

    public bool $is_active = true;

    public ?string $remarks = null;

    public function mount(?Employee $employee = null): void
    {
        // Laravel's container auto-instantiates an empty Employee() when no
        // `employee` prop is passed, rather than leaving $employee as null —
        // so check `exists`, not truthiness (same gotcha as company-form).
        $this->employee = ($employee && $employee->exists) ? $employee : null;

        if ($this->employee) {
            $employee = $this->employee;
            $this->name = $employee->name;
            $this->phone = $employee->phone;
            $this->position = $employee->position;
            $this->monthly_salary = (string) $employee->monthly_salary;
            $this->is_active = $employee->is_active;
            $this->remarks = $employee->remarks;
        }
    }

    public function save(): void
    {
        Gate::authorize($this->employee ? 'employees.modify' : 'employees.create');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'string', 'max:255'],
            'monthly_salary' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $employee = $this->employee ?? new Employee;
        $employee->fill($validated);
        $employee->save();

        session()->flash('status', $this->employee ? 'Staff member updated.' : 'Staff member added.');

        $this->redirect(route('employees.index'), navigate: true);
    }
}; ?>

<form wire:submit="save" class="space-y-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-800">
    <div>
        <x-input-label for="name" value="Name" />
        <x-text-input wire:model="name" id="name" placeholder="e.g. Mr. Monir" class="mt-1 block w-full" required autofocus />
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="phone" value="Phone" />
        <x-text-input wire:model="phone" id="phone" placeholder="e.g. 01712345678" class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get('phone')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="position" value="Position" />
        <x-text-input wire:model="position" id="position" placeholder="e.g. Driver, Floor Supervisor" class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get('position')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="monthly_salary" value="Monthly Salary" />
        <x-text-input wire:model="monthly_salary" id="monthly_salary" type="number" step="0.01" min="0" placeholder="e.g. 15000.00" class="mt-1 block w-full" required />
        <x-input-error :messages="$errors->get('monthly_salary')" class="mt-2" />
    </div>

    <div class="flex items-center gap-2">
        <input type="checkbox" wire:model="is_active" id="is_active" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
        <x-input-label for="is_active" value="Active" class="!mb-0" />
    </div>

    <div>
        <x-input-label for="remarks" value="Remarks" />
        <x-textarea-input wire:model="remarks" id="remarks" placeholder="Any other detail about this staff member" class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get('remarks')" class="mt-2" />
    </div>

    <div class="flex items-center justify-end gap-3">
        <x-secondary-button :href="route('employees.index')" wire:navigate>
            Cancel
        </x-secondary-button>
        <x-primary-button>
            {{ $employee ? 'Save Changes' : 'Add Staff' }}
        </x-primary-button>
    </div>
</form>
