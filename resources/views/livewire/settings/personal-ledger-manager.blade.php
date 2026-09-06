<?php

use App\Models\PersonalContact;
use App\Models\PersonalPayment;
use App\Models\PersonalSale;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    private const PER_PAGE = 15;

    #[Url(as: 'plview', history: true)]
    public string $activeView = 'contacts';

    #[Url(as: 'contact', history: true)]
    public string $contactFilter = '';

    public ?int $editingContactId = null;

    public string $contact_name = '';

    public ?string $contact_factory_name = null;

    public ?string $contact_phone = null;

    public ?string $contact_remarks = null;

    public ?int $confirmingDeleteContactId = null;

    public ?int $editingSaleId = null;

    public ?int $sale_contact_id = null;

    public string $sale_date = '';

    public string $sale_description = '';

    public ?string $sale_amount = null;

    public ?string $sale_remarks = null;

    public ?int $confirmingDeleteSaleId = null;

    public ?int $editingPaymentId = null;

    public ?int $payment_contact_id = null;

    public string $payment_date = '';

    public ?string $payment_amount = null;

    public ?string $payment_remarks = null;

    public ?int $confirmingDeletePaymentId = null;

    /**
     * Captured once in mount() — a manually-built LengthAwarePaginator needs
     * an explicit 'path', and request()->url() would otherwise resolve to
     * Livewire's own update endpoint on any re-render, not this page's
     * real URL.
     */
    public string $paginationPath = '';

    public function mount(): void
    {
        $this->paginationPath = request()->url();
    }

    public function switchView(string $view): void
    {
        $this->activeView = $view;
    }

    public function updatingContactFilter(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, string>
     */
    private function urlQueryState(): array
    {
        return array_filter([
            'plview' => $this->activeView,
            'contact' => $this->contactFilter,
        ], fn ($value) => $value !== '');
    }

    /**
     * Every contact belonging to the current Super Admin — this ledger is
     * private, so no query anywhere in this component is ever allowed to
     * see another user's contacts, sales or payments.
     */
    private function ownContacts()
    {
        return PersonalContact::where('user_id', auth()->id());
    }

    public function startCreateContact(): void
    {
        $this->editingContactId = null;
        $this->contact_name = '';
        $this->contact_factory_name = null;
        $this->contact_phone = null;
        $this->contact_remarks = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'personal-contact-form');
    }

    public function startEditContact(int $contactId): void
    {
        $contact = $this->ownContacts()->findOrFail($contactId);
        $this->editingContactId = $contact->id;
        $this->contact_name = $contact->name;
        $this->contact_factory_name = $contact->factory_name;
        $this->contact_phone = $contact->phone;
        $this->contact_remarks = $contact->remarks;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'personal-contact-form');
    }

    public function saveContact(): void
    {
        Gate::authorize('personal-ledger.manage');

        $validated = $this->validate([
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_factory_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $attributes = [
            'name' => trim($validated['contact_name']),
            'factory_name' => $validated['contact_factory_name'] ? trim($validated['contact_factory_name']) : null,
            'phone' => $validated['contact_phone'] ? trim($validated['contact_phone']) : null,
            'remarks' => $validated['contact_remarks'] ? trim($validated['contact_remarks']) : null,
        ];

        if ($this->editingContactId) {
            $this->ownContacts()->whereKey($this->editingContactId)->update($attributes);
        } else {
            $attributes['user_id'] = auth()->id();
            PersonalContact::create($attributes);
        }

        $this->dispatch('close-modal', 'personal-contact-form');
        session()->flash('status', $this->editingContactId ? 'Contact updated.' : 'Contact added.');
    }

    public function confirmDeleteContact(int $contactId): void
    {
        $this->confirmingDeleteContactId = $contactId;
        $this->dispatch('open-modal', 'confirm-personal-contact-deletion');
    }

    public function deleteContact(): void
    {
        Gate::authorize('personal-ledger.manage');

        if ($this->confirmingDeleteContactId) {
            $this->ownContacts()->whereKey($this->confirmingDeleteContactId)->delete();
        }

        $this->confirmingDeleteContactId = null;
        $this->dispatch('close-modal', 'confirm-personal-contact-deletion');
        session()->flash('status', 'Contact and its sales/payments deleted.');
    }

    public function startCreateSale(): void
    {
        $this->editingSaleId = null;
        $this->sale_contact_id = $this->contactFilter ? (int) $this->contactFilter : null;
        $this->sale_date = now()->toDateString();
        $this->sale_description = '';
        $this->sale_amount = null;
        $this->sale_remarks = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'personal-sale-form');
    }

    public function startEditSale(int $saleId): void
    {
        $sale = PersonalSale::whereHas('contact', fn ($query) => $query->where('user_id', auth()->id()))->findOrFail($saleId);
        $this->editingSaleId = $sale->id;
        $this->sale_contact_id = $sale->personal_contact_id;
        $this->sale_date = $sale->sale_date->format('Y-m-d');
        $this->sale_description = $sale->description;
        $this->sale_amount = (string) $sale->amount;
        $this->sale_remarks = $sale->remarks;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'personal-sale-form');
    }

    public function saveSale(): void
    {
        Gate::authorize('personal-ledger.manage');

        $validated = $this->validate([
            'sale_contact_id' => ['required', 'integer', Rule::exists('personal_contacts', 'id')->where('user_id', auth()->id())],
            'sale_date' => ['required', 'date'],
            'sale_description' => ['required', 'string', 'max:255'],
            'sale_amount' => ['required', 'numeric', 'min:0.01'],
            'sale_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $attributes = [
            'personal_contact_id' => $validated['sale_contact_id'],
            'sale_date' => $validated['sale_date'],
            'description' => trim($validated['sale_description']),
            'amount' => $validated['sale_amount'],
            'remarks' => $validated['sale_remarks'] ? trim($validated['sale_remarks']) : null,
        ];

        if ($this->editingSaleId) {
            PersonalSale::whereKey($this->editingSaleId)->update($attributes);
        } else {
            PersonalSale::create($attributes);
        }

        $this->dispatch('close-modal', 'personal-sale-form');
        session()->flash('status', $this->editingSaleId ? 'Sale updated.' : 'Sale recorded.');
    }

    public function confirmDeleteSale(int $saleId): void
    {
        $this->confirmingDeleteSaleId = $saleId;
        $this->dispatch('open-modal', 'confirm-personal-sale-deletion');
    }

    public function deleteSale(): void
    {
        Gate::authorize('personal-ledger.manage');

        if ($this->confirmingDeleteSaleId) {
            PersonalSale::whereHas('contact', fn ($query) => $query->where('user_id', auth()->id()))
                ->whereKey($this->confirmingDeleteSaleId)
                ->delete();
        }

        $this->confirmingDeleteSaleId = null;
        $this->dispatch('close-modal', 'confirm-personal-sale-deletion');
        session()->flash('status', 'Sale deleted.');
    }

    public function startCreatePayment(): void
    {
        $this->editingPaymentId = null;
        $this->payment_contact_id = $this->contactFilter ? (int) $this->contactFilter : null;
        $this->payment_date = now()->toDateString();
        $this->payment_amount = null;
        $this->payment_remarks = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'personal-payment-form');
    }

    public function startEditPayment(int $paymentId): void
    {
        $payment = PersonalPayment::whereHas('contact', fn ($query) => $query->where('user_id', auth()->id()))->findOrFail($paymentId);
        $this->editingPaymentId = $payment->id;
        $this->payment_contact_id = $payment->personal_contact_id;
        $this->payment_date = $payment->payment_date->format('Y-m-d');
        $this->payment_amount = (string) $payment->amount;
        $this->payment_remarks = $payment->remarks;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'personal-payment-form');
    }

    public function savePayment(): void
    {
        Gate::authorize('personal-ledger.manage');

        $validated = $this->validate([
            'payment_contact_id' => ['required', 'integer', Rule::exists('personal_contacts', 'id')->where('user_id', auth()->id())],
            'payment_date' => ['required', 'date'],
            'payment_amount' => ['required', 'numeric', 'min:0.01'],
            'payment_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $attributes = [
            'personal_contact_id' => $validated['payment_contact_id'],
            'payment_date' => $validated['payment_date'],
            'amount' => $validated['payment_amount'],
            'remarks' => $validated['payment_remarks'] ? trim($validated['payment_remarks']) : null,
        ];

        if ($this->editingPaymentId) {
            PersonalPayment::whereKey($this->editingPaymentId)->update($attributes);
        } else {
            PersonalPayment::create($attributes);
        }

        $this->dispatch('close-modal', 'personal-payment-form');
        session()->flash('status', $this->editingPaymentId ? 'Payment updated.' : 'Payment recorded.');
    }

    public function confirmDeletePayment(int $paymentId): void
    {
        $this->confirmingDeletePaymentId = $paymentId;
        $this->dispatch('open-modal', 'confirm-personal-payment-deletion');
    }

    public function deletePayment(): void
    {
        Gate::authorize('personal-ledger.manage');

        if ($this->confirmingDeletePaymentId) {
            PersonalPayment::whereHas('contact', fn ($query) => $query->where('user_id', auth()->id()))
                ->whereKey($this->confirmingDeletePaymentId)
                ->delete();
        }

        $this->confirmingDeletePaymentId = null;
        $this->dispatch('close-modal', 'confirm-personal-payment-deletion');
        session()->flash('status', 'Payment deleted.');
    }

    /**
     * Sales and payments merged into one chronological ledger — a sale adds
     * to what a contact owes, a payment reduces it, and this is the only
     * place the two are shown side by side so the "they pay partially over
     * time" pattern the client described is actually visible at a glance.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function transactionLedger(): LengthAwarePaginator
    {
        $sales = PersonalSale::whereHas('contact', fn ($query) => $query->where('user_id', auth()->id()))
            ->with('contact')
            ->when($this->contactFilter, fn ($query) => $query->where('personal_contact_id', $this->contactFilter))
            ->get()
            ->map(fn (PersonalSale $sale) => [
                'type' => 'sale',
                'id' => $sale->id,
                'contact' => $sale->contact,
                'date' => $sale->sale_date,
                'label' => $sale->description,
                'amount' => (float) $sale->amount,
                'remarks' => $sale->remarks,
            ]);

        $payments = PersonalPayment::whereHas('contact', fn ($query) => $query->where('user_id', auth()->id()))
            ->with('contact')
            ->when($this->contactFilter, fn ($query) => $query->where('personal_contact_id', $this->contactFilter))
            ->get()
            ->map(fn (PersonalPayment $payment) => [
                'type' => 'payment',
                'id' => $payment->id,
                'contact' => $payment->contact,
                'date' => $payment->payment_date,
                'label' => 'Cash payment',
                'amount' => (float) $payment->amount,
                'remarks' => $payment->remarks,
            ]);

        $all = $sales->concat($payments)
            ->sortByDesc(fn ($row) => $row['date']->format('Y-m-d').'-'.$row['id'])
            ->values();

        return (new LengthAwarePaginator(
            $all->forPage($this->getPage(), self::PER_PAGE)->values(),
            $all->count(),
            self::PER_PAGE,
            $this->getPage(),
            ['path' => $this->paginationPath]
        ))->appends($this->urlQueryState());
    }

    public function with(): array
    {
        $contacts = $this->ownContacts()->orderBy('name')->get();

        return [
            'contacts' => $contacts,
            'contactsWithTotals' => $contacts->map(fn (PersonalContact $contact) => [
                'contact' => $contact,
                'totalSold' => (float) $contact->sales()->sum('amount'),
                'totalPaid' => (float) $contact->payments()->sum('amount'),
                'balanceDue' => $contact->balanceDue,
            ]),
            'totalOutstanding' => $contacts->sum(fn (PersonalContact $contact) => $contact->balanceDue),
            'transactions' => $this->transactionLedger(),
        ];
    }
}; ?>

<div class="space-y-4">
    <div>
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Personal Ledger</h2>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
            Your own side transactions with known people at other factories — separate from Rezia
            Enterprise's business records. Cash only, and they often pay in parts, so each contact's
            balance is sales minus payments received so far. Visible only to you.
        </p>
    </div>

    <div class="rounded-xl border border-brand-200 bg-brand-50 p-4 shadow-sm dark:border-brand-800 dark:bg-brand-900/20">
        <p class="text-xs text-brand-700 dark:text-brand-300">Total Outstanding — All Contacts</p>
        <p class="mt-1 text-lg font-semibold text-brand-900 dark:text-white">{{ number_format($totalOutstanding, 2) }}</p>
    </div>

    <div class="flex flex-wrap gap-2 border-b border-slate-200 dark:border-slate-700">
        <button
            type="button"
            wire:click="switchView('contacts')"
            class="border-b-2 px-1 pb-2 text-sm font-medium {{ $activeView === 'contacts' ? 'border-brand-600 text-brand-600 dark:border-brand-400 dark:text-brand-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
        >
            Contacts
        </button>
        <button
            type="button"
            wire:click="switchView('transactions')"
            class="border-b-2 px-1 pb-2 text-sm font-medium {{ $activeView === 'transactions' ? 'border-brand-600 text-brand-600 dark:border-brand-400 dark:text-brand-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
        >
            Sales &amp; Payments
        </button>
    </div>

    @if ($activeView === 'contacts')
        <div class="flex justify-end">
            <x-primary-button type="button" wire:click="startCreateContact">
                + Add Contact
            </x-primary-button>
        </div>

        @forelse ($contactsWithTotals as $row)
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $row['contact']->name }}</h3>
                            @if ($row['contact']->factory_name)
                                <span class="text-xs text-slate-400 dark:text-slate-500">{{ $row['contact']->factory_name }}</span>
                            @endif
                        </div>
                        @if ($row['contact']->phone)
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $row['contact']->phone }}</p>
                        @endif
                        @if ($row['contact']->remarks)
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $row['contact']->remarks }}</p>
                        @endif
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                            Sold {{ number_format($row['totalSold'], 2) }} · Paid {{ number_format($row['totalPaid'], 2) }}
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-slate-400 dark:text-slate-500">Balance Due</p>
                        <p class="text-lg font-semibold {{ $row['balanceDue'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                            {{ number_format($row['balanceDue'], 2) }}
                        </p>
                    </div>
                </div>

                <div class="mt-4 flex items-center gap-3">
                    <x-secondary-button type="button" wire:click="startEditContact({{ $row['contact']->id }})">
                        Edit
                    </x-secondary-button>
                    <x-danger-button type="button" wire:click="confirmDeleteContact({{ $row['contact']->id }})">
                        Delete
                    </x-danger-button>
                </div>
            </div>
        @empty
            <x-empty-state
                title="No contacts yet"
                message="Add a contact for each person you personally sell goods or oils to."
            />
        @endforelse
    @elseif ($activeView === 'transactions')
        <div class="flex flex-wrap items-center gap-3">
            <x-select-input wire:model.live="contactFilter" class="w-full sm:w-56">
                <option value="">All contacts</option>
                @foreach ($contacts as $contact)
                    <option value="{{ $contact->id }}">{{ $contact->name }}</option>
                @endforeach
            </x-select-input>

            <x-secondary-button type="button" wire:click="startCreateSale">
                + Record Sale
            </x-secondary-button>
            <x-secondary-button type="button" wire:click="startCreatePayment">
                + Record Payment
            </x-secondary-button>
        </div>

        @forelse ($transactions as $row)
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-badge color="{{ $row['type'] === 'sale' ? 'amber' : 'green' }}">
                                {{ $row['type'] === 'sale' ? 'Sale' : 'Payment' }}
                            </x-badge>
                            <span class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $row['contact']->name }}</span>
                            <span class="text-xs text-slate-400 dark:text-slate-500">{{ $row['date']->format('d M Y') }}</span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            {{ $row['label'] }}
                            @if ($row['remarks'])
                                · {{ $row['remarks'] }}
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-4">
                        <span class="shrink-0 text-sm font-semibold {{ $row['type'] === 'sale' ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                            {{ $row['type'] === 'sale' ? '+' : '-' }}{{ number_format($row['amount'], 2) }}
                        </span>
                        <div class="flex items-center gap-3 text-xs">
                            <button type="button" wire:click="{{ $row['type'] === 'sale' ? 'startEditSale' : 'startEditPayment' }}({{ $row['id'] }})" class="font-medium text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                                Edit
                            </button>
                            <button type="button" wire:click="{{ $row['type'] === 'sale' ? 'confirmDeleteSale' : 'confirmDeletePayment' }}({{ $row['id'] }})" class="font-medium text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <x-empty-state
                title="No sales or payments yet"
                message="Record a sale whenever you personally supply goods or oils, and a payment whenever cash comes in against it."
            />
        @endforelse

        {{ $transactions->links('pagination::simple-tailwind') }}
    @endif

    <x-modal name="personal-contact-form" focusable>
        <form wire:submit="saveContact" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingContactId ? 'Edit Contact' : 'Add Contact' }}
            </h2>

            <div>
                <x-input-label for="contact_name" value="Name" />
                <x-text-input wire:model="contact_name" id="contact_name" placeholder="e.g. Karim Uddin" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('contact_name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="contact_factory_name" value="Factory Name" />
                <x-text-input wire:model="contact_factory_name" id="contact_factory_name" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('contact_factory_name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="contact_phone" value="Phone" />
                <x-text-input wire:model="contact_phone" id="contact_phone" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('contact_phone')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="contact_remarks" value="Remarks" />
                <x-textarea-input wire:model="contact_remarks" id="contact_remarks" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('contact_remarks')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-primary-button>{{ $editingContactId ? 'Save Changes' : 'Add Contact' }}</x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-personal-contact-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this contact?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                This also deletes every sale and payment recorded against them. This cannot be undone.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="deleteContact">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="personal-sale-form" focusable>
        <form wire:submit="saveSale" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingSaleId ? 'Edit Sale' : 'Record Sale' }}
            </h2>

            <div>
                <x-input-label for="sale_contact_id" value="Contact" />
                <x-select-input wire:model="sale_contact_id" id="sale_contact_id" class="mt-1 block w-full" required>
                    <option value="">Select a contact…</option>
                    @foreach ($contacts as $contact)
                        <option value="{{ $contact->id }}">{{ $contact->name }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('sale_contact_id')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="sale_date" value="Sale Date" />
                <x-text-input wire:model="sale_date" id="sale_date" type="date" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('sale_date')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="sale_description" value="What was sold" />
                <x-text-input wire:model="sale_description" id="sale_description" placeholder="e.g. 5 drums of oil" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('sale_description')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="sale_amount" value="Amount" />
                <x-text-input wire:model="sale_amount" id="sale_amount" type="number" step="0.01" min="0" placeholder="e.g. 15000.00" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('sale_amount')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="sale_remarks" value="Remarks" />
                <x-textarea-input wire:model="sale_remarks" id="sale_remarks" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('sale_remarks')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-primary-button>{{ $editingSaleId ? 'Save Changes' : 'Record Sale' }}</x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-personal-sale-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this sale?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">This cannot be undone.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="deleteSale">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="personal-payment-form" focusable>
        <form wire:submit="savePayment" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingPaymentId ? 'Edit Payment' : 'Record Payment' }}
            </h2>

            <div>
                <x-input-label for="payment_contact_id" value="Contact" />
                <x-select-input wire:model="payment_contact_id" id="payment_contact_id" class="mt-1 block w-full" required>
                    <option value="">Select a contact…</option>
                    @foreach ($contacts as $contact)
                        <option value="{{ $contact->id }}">{{ $contact->name }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('payment_contact_id')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="payment_date" value="Payment Date" />
                <x-text-input wire:model="payment_date" id="payment_date" type="date" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('payment_date')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="payment_amount" value="Cash Amount Received" />
                <x-text-input wire:model="payment_amount" id="payment_amount" type="number" step="0.01" min="0" placeholder="e.g. 5000.00" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('payment_amount')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="payment_remarks" value="Remarks" />
                <x-textarea-input wire:model="payment_remarks" id="payment_remarks" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('payment_remarks')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-primary-button>{{ $editingPaymentId ? 'Save Changes' : 'Record Payment' }}</x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-personal-payment-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this payment?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">This cannot be undone.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="deletePayment">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
