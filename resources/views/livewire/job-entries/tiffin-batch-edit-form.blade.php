<?php

use App\Models\Company;
use App\Models\JobEntry;
use App\Models\TiffinDepartment;
use App\Models\TiffinItemPurchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Component;

new class extends Component
{
    public Company $company;

    public TiffinDepartment $department;

    public string $date;

    public string $entry_date = '';

    public string $inChargeSelection = '';

    public bool $is_off_day = false;

    public ?string $remarks = null;

    /** @var array<string, string> */
    public array $quantities = [];

    /** @var array<string, string> */
    public array $costRates = [];

    /** @var array<string, string> */
    public array $billRates = [];

    /** @var array<string, int> */
    public array $entryIds = [];

    public bool $carriesEggBuffer = false;

    public function mount(Company $company, TiffinDepartment $department, string $date): void
    {
        $this->company = $company;
        $this->department = $department;
        $this->date = $date;

        $entries = JobEntry::where('company_id', $company->id)
            ->where('tiffin_department_id', $department->id)
            ->whereDate('entry_date', $date)
            ->orderBy('id')
            ->get();

        if ($entries->isEmpty()) {
            abort(404);
        }

        if ($entries->contains(fn (JobEntry $entry) => $entry->isBilled)) {
            session()->flash('error', 'This Tiffin order has already been billed and can\'t be edited.');
            $this->redirect(route('job-entries.index'), navigate: true);

            return;
        }

        $first = $entries->first();
        $this->entry_date = $first->entry_date->format('Y-m-d');
        $this->inChargeSelection = $first->in_charge_id ? (string) $first->in_charge_id : '';
        $this->is_off_day = $first->is_off_day;
        $this->remarks = $first->remarks;

        // The +5 Egg buffer is sent once for the whole company's delivery
        // that day, not once per department — only whichever department
        // absorbed it when the batch was saved carries it here too.
        $this->carriesEggBuffer = $this->department->id === $this->eggBufferDepartmentId();

        foreach ($entries as $entry) {
            $this->quantities[$entry->supply_type] = ($entry->supply_type === 'Egg' && $this->carriesEggBuffer)
                ? number_format((float) $entry->quantity - config('tiffin.egg_buffer_quantity'), 2, '.', '')
                : (string) $entry->quantity;
            $this->costRates[$entry->supply_type] = (string) $entry->cost_rate;
            $this->billRates[$entry->supply_type] = (string) $entry->bill_rate;
            $this->entryIds[$entry->supply_type] = $entry->id;
        }

        $this->applyPurchaseLocks();
    }

    /**
     * Which department currently carries the company's Egg buffer for this
     * day — the department (across all of them, not just this one) whose
     * Egg entry was saved first, matching the create-batch form's
     * "alphabetically first department with Egg" convention.
     */
    private function eggBufferDepartmentId(): ?int
    {
        return JobEntry::where('company_id', $this->company->id)
            ->whereDate('entry_date', $this->entry_date)
            ->where('supply_type', 'Egg')
            ->whereNotNull('tiffin_department_id')
            ->with('tiffinDepartment')
            ->get()
            ->sortBy(fn (JobEntry $entry) => $entry->tiffinDepartment->name)
            ->first()
            ?->tiffin_department_id;
    }

    public function updated(string $name): void
    {
        if ($name === 'entry_date') {
            $this->applyPurchaseLocks();
        }
    }

    /**
     * Same lock as the create-batch form: force cost rate to match today's
     * purchase record wherever one exists, without wiping anything else.
     */
    private function applyPurchaseLocks(): void
    {
        foreach (array_keys($this->entryIds) as $itemName) {
            $purchase = TiffinItemPurchase::findFor($itemName, $this->entry_date);

            if ($purchase) {
                $this->costRates[$itemName] = (string) $purchase->cost_rate;
            }
        }
    }

    public function save(): void
    {
        Gate::authorize('job_entries.modify');

        $rules = [
            'entry_date' => ['required', 'date'],
        ];

        foreach ($this->entryIds as $itemName => $id) {
            $rules["quantities.{$itemName}"] = ['required', 'numeric', 'min:0'];
            $rules["costRates.{$itemName}"] = ['required', 'numeric', 'min:0'];

            // Only Egg bills — the whole meal is one fixed rate per person.
            if ($itemName === 'Egg') {
                $rules["billRates.{$itemName}"] = ['required', 'numeric', 'min:0'];
            }
        }

        $this->validate($rules);

        DB::transaction(function () {
            $inChargeId = $this->inChargeSelection !== '' ? (int) $this->inChargeSelection : null;

            foreach ($this->entryIds as $itemName => $id) {
                $isEgg = $itemName === 'Egg';
                $headcountOrQty = (float) $this->quantities[$itemName];
                $storedQuantity = ($isEgg && $this->carriesEggBuffer) ? $headcountOrQty + config('tiffin.egg_buffer_quantity') : $headcountOrQty;
                $billRate = $isEgg ? (float) ($this->billRates[$itemName] ?? 0) : 0.0;

                // A disabled input isn't a security boundary — re-check
                // authoritatively at save time, same as the create path.
                $purchase = TiffinItemPurchase::findFor($itemName, $this->entry_date);
                $costRate = $purchase ? (float) $purchase->cost_rate : (float) $this->costRates[$itemName];

                JobEntry::findOrFail($id)->update([
                    'entry_date' => $this->entry_date,
                    'in_charge_id' => $inChargeId,
                    'is_off_day' => $this->is_off_day,
                    'remarks' => $this->remarks,
                    'quantity' => $storedQuantity,
                    'cost_rate' => $costRate,
                    'bill_rate' => $billRate,
                    'cost_amount' => round($storedQuantity * $costRate, 2),
                    // Bill is always headcount × rate, never the buffered
                    // egg count × rate.
                    'bill_amount' => round($headcountOrQty * $billRate, 2),
                ]);
            }
        });

        session()->flash('status', 'Tiffin order updated.');

        $this->redirect(route('job-entries.index'), navigate: true);
    }

    public function with(): array
    {
        $purchaseLocks = [];
        $eggBuffer = config('tiffin.egg_buffer_quantity');

        foreach (array_keys($this->entryIds) as $itemName) {
            $purchaseLocks[$itemName] = TiffinItemPurchase::findFor($itemName, $this->entry_date);
        }

        $eggQuantity = $this->quantities['Egg'] ?? null;
        $eggBufferDepartmentName = ! $this->carriesEggBuffer && isset($this->entryIds['Egg'])
            ? TiffinDepartment::find($this->eggBufferDepartmentId())?->name
            : null;

        return [
            'inCharges' => User::where('is_active', true)->orderBy('name')->get(),
            'purchaseLocks' => $purchaseLocks,
            'eggBuffer' => $eggBuffer,
            'actualEggQuantity' => ($this->carriesEggBuffer && is_numeric($eggQuantity)) ? (float) $eggQuantity + $eggBuffer : null,
            'eggBufferDepartmentName' => $eggBufferDepartmentName,
        ];
    }
}; ?>

<form wire:submit="save" class="space-y-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-800">
    <div>
        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $company->name }} — {{ $department->name }}</h3>
        <p class="text-xs text-slate-400 dark:text-slate-500">Editing this department's whole Tiffin order together.</p>
    </div>

    <div>
        <x-input-label for="entry_date" value="Entry Date" />
        <x-text-input wire:model.live="entry_date" id="entry_date" type="date" class="mt-1 block w-full" required />
        <x-input-error :messages="$errors->get('entry_date')" class="mt-2" />
    </div>

    <div class="space-y-3">
        @foreach ($entryIds as $itemName => $id)
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900/50">
                <div class="flex items-center gap-2">
                    <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $itemName }}</p>
                    @if ($itemName === 'Egg')
                        <x-badge color="brand">Bills</x-badge>
                    @endif
                </div>
                <div class="mt-2 grid {{ $itemName === 'Egg' ? 'grid-cols-3' : 'grid-cols-2' }} gap-2">
                    <div>
                        <x-input-label :for="'qty_'.$itemName" :value="$itemName === 'Egg' ? 'Headcount' : 'Quantity'" class="!mb-0 text-xs" />
                        <x-text-input wire:model.live.debounce.400ms="quantities.{{ $itemName }}" :id="'qty_'.$itemName" type="number" step="0.01" min="0" class="mt-1 block w-full text-sm" />
                        <x-input-error :messages="$errors->get('quantities.'.$itemName)" class="mt-1" />
                        @if ($itemName === 'Egg' && $actualEggQuantity !== null)
                            <p class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">
                                +{{ rtrim(rtrim(number_format($eggBuffer, 2), '0'), '.') }} buffer (once for the whole company) → {{ rtrim(rtrim(number_format($actualEggQuantity, 2), '0'), '.') }} eggs costed
                            </p>
                        @elseif ($itemName === 'Egg' && $eggBufferDepartmentName)
                            <p class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">
                                Buffer already added under {{ $eggBufferDepartmentName }} for the day
                            </p>
                        @endif
                    </div>
                    <div>
                        <x-input-label :for="'cost_'.$itemName" value="Cost Rate" class="!mb-0 text-xs" />
                        <x-text-input wire:model="costRates.{{ $itemName }}" :id="'cost_'.$itemName" type="number" step="0.01" min="0" class="mt-1 block w-full text-sm" :disabled="(bool) ($purchaseLocks[$itemName] ?? null)" />
                        <x-input-error :messages="$errors->get('costRates.'.$itemName)" class="mt-1" />
                    </div>
                    @if ($itemName === 'Egg')
                        <div>
                            <x-input-label :for="'bill_'.$itemName" value="Bill Rate" class="!mb-0 text-xs" />
                            <x-text-input wire:model="billRates.{{ $itemName }}" :id="'bill_'.$itemName" type="number" step="0.01" min="0" class="mt-1 block w-full text-sm" />
                            <x-input-error :messages="$errors->get('billRates.'.$itemName)" class="mt-1" />
                        </div>
                    @endif
                </div>

                @if ($purchaseLocks[$itemName] ?? null)
                    <p class="mt-2 text-xs text-brand-700 dark:text-brand-300">
                        Locked from the
                        {{ $purchaseLocks[$itemName]->purchase_date->isSameDay($entry_date) ? "day's" : $purchaseLocks[$itemName]->purchase_date->format('d M Y')."'s" }}
                        purchase: {{ rtrim(rtrim(number_format((float) $purchaseLocks[$itemName]->quantity, 2), '0'), '.') }} @ {{ number_format((float) $purchaseLocks[$itemName]->cost_rate, 2) }}
                        @if ($purchaseLocks[$itemName]->supplier_name)
                            from {{ $purchaseLocks[$itemName]->supplier_name }}
                        @endif
                        — <a href="{{ route('egg-purchases.index') }}" wire:navigate class="font-medium underline">View Egg Purchases</a>
                    </p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="flex items-center gap-2">
        <input type="checkbox" wire:model="is_off_day" id="is_off_day" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
        <x-input-label for="is_off_day" value="Off Day (no billing)" class="!mb-0" />
    </div>

    <div>
        <x-input-label for="inChargeSelection" value="In-Charge" />
        <x-select-input wire:model="inChargeSelection" id="inChargeSelection" class="mt-1 block w-full">
            <option value="">None</option>
            @foreach ($inCharges as $inCharge)
                <option value="{{ $inCharge->id }}">{{ $inCharge->name }}</option>
            @endforeach
        </x-select-input>
    </div>

    <div>
        <x-input-label for="remarks" value="Remarks" />
        <x-textarea-input wire:model="remarks" id="remarks" placeholder="e.g. Off day due to public holiday" class="mt-1 block w-full" />
    </div>

    <div class="flex items-center justify-end gap-3">
        <x-secondary-button :href="route('job-entries.index')" wire:navigate>
            Cancel
        </x-secondary-button>
        <x-primary-button>
            Save Changes
        </x-primary-button>
    </div>
</form>
