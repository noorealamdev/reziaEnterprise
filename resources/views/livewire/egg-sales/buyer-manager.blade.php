<?php

use App\Models\EggBuyer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    public ?int $editingBuyerId = null;

    public string $name = '';

    public ?string $phone = null;

    public ?string $address = null;

    public ?string $remarks = null;

    public ?int $confirmingDeleteBuyerId = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function startCreate(): void
    {
        $this->editingBuyerId = null;
        $this->reset(['name', 'phone', 'address', 'remarks']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-buyer-form');
    }

    public function startEdit(int $buyerId): void
    {
        $buyer = EggBuyer::findOrFail($buyerId);
        $this->editingBuyerId = $buyer->id;
        $this->name = $buyer->name;
        $this->phone = $buyer->phone;
        $this->address = $buyer->address;
        $this->remarks = $buyer->remarks;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-buyer-form');
    }

    public function save(): void
    {
        Gate::authorize($this->editingBuyerId ? 'egg_sales.modify' : 'egg_sales.create');

        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('egg_buyers', 'name')->ignore($this->editingBuyerId)],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $attributes = [
            'name' => $validated['name'],
            'phone' => $validated['phone'] ? trim($validated['phone']) : null,
            'address' => $validated['address'] ? trim($validated['address']) : null,
            'remarks' => $validated['remarks'] ? trim($validated['remarks']) : null,
        ];

        if ($this->editingBuyerId) {
            EggBuyer::whereKey($this->editingBuyerId)->update($attributes);
        } else {
            EggBuyer::create([...$attributes, 'created_by' => auth()->id()]);
        }

        $this->dispatch('close-modal', 'egg-buyer-form');
        $this->notify($this->editingBuyerId ? 'Buyer updated.' : 'Buyer added.');
    }

    public function toggleActive(int $buyerId): void
    {
        Gate::authorize('egg_sales.modify');

        $buyer = EggBuyer::findOrFail($buyerId);
        $buyer->update(['is_active' => ! $buyer->is_active]);
        $this->notify($buyer->is_active ? "{$buyer->name} is active again." : "{$buyer->name} is now inactive. Their records are kept.");
    }

    public function confirmDelete(int $buyerId): void
    {
        $this->confirmingDeleteBuyerId = $buyerId;
        $this->dispatch('open-modal', 'confirm-egg-buyer-deletion');
    }

    /**
     * A buyer with records is never deleted, because that would break old
     * bills; staff mark them inactive instead.
     */
    public function delete(): void
    {
        Gate::authorize('egg_sales.modify');

        $buyer = EggBuyer::withCount(['sales', 'invoices'])->find($this->confirmingDeleteBuyerId);
        $this->confirmingDeleteBuyerId = null;
        $this->dispatch('close-modal', 'confirm-egg-buyer-deletion');

        if (! $buyer) {
            return;
        }

        if ($buyer->sales_count > 0 || $buyer->invoices_count > 0) {
            $this->notify("{$buyer->name} has sales or bills and can't be deleted. Mark them inactive instead.", 'error');

            return;
        }

        $buyer->delete();
        $this->notify('Buyer deleted.');
    }

    private function notify(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', message: $message, type: $type);
    }

    public function with(): array
    {
        $term = trim($this->search);

        return [
            'buyers' => EggBuyer::query()
                ->when($term !== '', fn ($query) => $query->where(fn ($match) => $match
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")))
                ->withCount(['sales', 'invoices'])
                ->withSum('sales as sold_total', 'sale_amount')
                ->withSum(['sales as billed_total' => fn ($query) => $query->whereNotNull('egg_invoice_id')], 'sale_amount')
                ->withSum('invoicePayments as paid_total', 'egg_invoice_payments.amount')
                ->withSum('cashHandovers as handed_total', 'amount')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->paginate(25),
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-text-input wire:model.live.debounce.300ms="search" aria-label="Search buyers" placeholder="Search by name or phone" class="w-full sm:w-72" />

        @can('egg_sales.create')
            <x-primary-button type="button" wire:click="startCreate">+ Add Buyer</x-primary-button>
        @endcan
    </div>

    @if ($buyers->isEmpty())
        <x-empty-state
            :title="trim($search) !== '' ? 'No buyers match this search' : 'No buyers yet'"
            :message="trim($search) !== '' ? 'Try a different name or phone number.' : 'Add a buyer before recording their first sale.'"
        />
    @else
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <table class="w-full min-w-[820px] text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                        <th class="px-4 py-3">Buyer</th>
                        <th class="px-4 py-3 text-right">Total Sold</th>
                        <th class="px-4 py-3 text-right">Not Billed</th>
                        <th class="px-4 py-3 text-right">Billed</th>
                        <th class="px-4 py-3 text-right">Paid</th>
                        <th class="px-4 py-3 text-right">Still Owed</th>
                        @can('egg_sales.modify')
                            <th class="px-4 py-3 text-right">Actions</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach ($buyers as $buyer)
                        @php
                            $owed = $buyer->uses_cash_wallet
                                ? max(0, (float) $buyer->sold_total - (float) $buyer->handed_total)
                                : max(0, (float) $buyer->billed_total - (float) $buyer->paid_total);
                            $notBilled = max(0, (float) $buyer->sold_total - (float) $buyer->billed_total);
                        @endphp
                        <tr wire:key="buyer-{{ $buyer->id }}" class="border-b border-slate-100 last:border-0 dark:border-slate-700/50">
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-900 dark:text-white">{{ $buyer->name }}</span>
                                @unless ($buyer->is_active)
                                    <x-badge color="slate" class="ml-1">Inactive</x-badge>
                                @endunless
                                @if ($buyer->uses_cash_wallet)
                                    <x-badge color="brand" class="ml-1">Cash wallet</x-badge>
                                @endif
                                @if ($buyer->phone || $buyer->address)
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ collect([$buyer->phone, $buyer->address])->filter()->implode(' · ') }}</span>
                                @endif
                                @if ($buyer->remarks)
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $buyer->remarks }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">{{ number_format((float) $buyer->sold_total, 2) }}</td>
                            @if ($buyer->uses_cash_wallet)
                                <td class="px-4 py-3 text-right text-slate-400 dark:text-slate-500">—</td>
                                <td class="px-4 py-3 text-right text-slate-400 dark:text-slate-500">—</td>
                                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">{{ number_format((float) $buyer->handed_total, 2) }}</td>
                            @else
                                <td class="px-4 py-3 text-right {{ $notBilled > 0 ? 'font-medium text-amber-800 dark:text-amber-300' : 'text-slate-600 dark:text-slate-400' }}">{{ number_format($notBilled, 2) }}</td>
                                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">{{ number_format((float) $buyer->billed_total, 2) }}</td>
                                <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">{{ number_format((float) $buyer->paid_total, 2) }}</td>
                            @endif
                            <td class="px-4 py-3 text-right font-semibold {{ $owed > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">{{ number_format($owed, 2) }}</td>
                            @can('egg_sales.modify')
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <button type="button" wire:click="startEdit({{ $buyer->id }})" class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Edit</button>
                                    @if ($buyer->sales_count > 0 || $buyer->invoices_count > 0)
                                        <button type="button" wire:click="toggleActive({{ $buyer->id }})" class="ml-3 font-medium text-slate-600 hover:text-slate-900 dark:text-slate-300">
                                            {{ $buyer->is_active ? 'Mark Inactive' : 'Make Active' }}
                                        </button>
                                    @else
                                        <button type="button" wire:click="confirmDelete({{ $buyer->id }})" class="ml-3 font-medium text-red-600 hover:text-red-700 dark:text-red-400">Delete</button>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $buyers->links() }}
    @endif

    @canany(['egg_sales.create', 'egg_sales.modify'])
    <x-modal name="egg-buyer-form" focusable>
        <form wire:submit="save" class="space-y-5 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">{{ $editingBuyerId ? 'Edit Buyer' : 'Add Buyer' }}</h2>

            <div>
                <x-input-label for="buyer_form_name" value="Name" />
                <x-text-input wire:model="name" id="buyer_form_name" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="buyer_form_phone" value="Phone (optional)" />
                <x-text-input wire:model="phone" id="buyer_form_phone" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('phone')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="buyer_form_address" value="Address (optional, prints on bills)" />
                <x-text-input wire:model="address" id="buyer_form_address" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('address')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="buyer_form_remarks" value="Remarks (optional)" />
                <x-textarea-input wire:model="remarks" id="buyer_form_remarks" rows="2" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('remarks')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-primary-button>{{ $editingBuyerId ? 'Save Changes' : 'Add Buyer' }}</x-primary-button>
            </div>
        </form>
    </x-modal>
    @endcanany

    @can('egg_sales.modify')
    <x-modal name="confirm-egg-buyer-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this buyer?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">This buyer has no sales or bills. This cannot be undone.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="delete">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>
    @endcan
</div>
