<?php

use App\Models\Newspaper;
use App\Models\NewspaperPayment;
use App\Models\SajjatTransaction;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    private const PER_PAGE = 30;

    #[Url(as: 'month', history: true)]
    public string $period = '';

    /** '', 'due', 'partial', 'paid' or 'inactive'. */
    #[Url(as: 'status', history: true)]
    public string $statusFilter = '';

    #[Url(as: 'q', history: true)]
    public string $search = '';

    public ?int $editingId = null;

    public string $name = '';

    public string $journalist_name = '';

    public string $phone = '';

    public ?string $whatsapp = null;

    public ?string $monthly_amount = null;

    public bool $is_active = true;

    public ?string $remarks = null;

    public ?int $payingNewspaperId = null;

    public string $pay_for_month = '';

    public ?string $pay_amount = null;

    public string $pay_paid_on = '';

    public string $pay_wallet = 'bkash';

    public ?string $pay_remarks = null;

    public ?int $historyNewspaperId = null;

    public ?int $confirmingDeleteId = null;

    public ?int $confirmingPaymentDeleteId = null;

    /**
     * Captured once in mount() — a manually-built LengthAwarePaginator needs
     * an explicit 'path', and request()->url() would otherwise resolve to
     * Livewire's own update endpoint on an AJAX re-render.
     */
    public string $paginationPath = '';

    public function mount(): void
    {
        $this->paginationPath = request()->url();

        if (! $this->validMonth($this->period)) {
            $this->period = now()->format('Y-m');
        }
    }

    public function updatingPeriod(): void
    {
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
     * The month comes straight from the URL (?month=…), so anything that
     * isn't a real Y-m value falls back to the current month.
     */
    private function validMonth(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value);
    }

    private function periodStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', ($this->validMonth($this->period) ? $this->period : now()->format('Y-m')).'-01')->startOfDay();
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->journalist_name = '';
        $this->phone = '';
        $this->whatsapp = null;
        $this->monthly_amount = null;
        $this->is_active = true;
        $this->remarks = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'newspaper-form');
    }

    public function startEdit(int $newspaperId): void
    {
        $newspaper = Newspaper::findOrFail($newspaperId);
        $this->editingId = $newspaper->id;
        $this->name = $newspaper->name;
        $this->journalist_name = $newspaper->journalist_name;
        $this->phone = $newspaper->phone;
        $this->whatsapp = $newspaper->whatsapp;
        $this->monthly_amount = $newspaper->monthly_amount !== null ? (string) $newspaper->monthly_amount : null;
        $this->is_active = $newspaper->is_active;
        $this->remarks = $newspaper->remarks;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'newspaper-form');
    }

    public function save(): void
    {
        Gate::authorize($this->editingId ? 'sajjat.newspapers.modify' : 'sajjat.newspapers.create');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'journalist_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'monthly_amount' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $data = [
            'name' => trim($validated['name']),
            'journalist_name' => trim($validated['journalist_name']),
            'phone' => trim($validated['phone']),
            'whatsapp' => filled($validated['whatsapp']) ? trim($validated['whatsapp']) : null,
            'monthly_amount' => filled($validated['monthly_amount']) ? $validated['monthly_amount'] : null,
            'is_active' => $validated['is_active'],
            'remarks' => filled($validated['remarks']) ? trim($validated['remarks']) : null,
        ];

        if ($this->editingId) {
            Newspaper::whereKey($this->editingId)->update($data);
        } else {
            Newspaper::create($data);
        }

        $this->dispatch('close-modal', 'newspaper-form');
        $this->notify($this->editingId ? 'Newspaper updated.' : 'Newspaper added.');
    }

    public function confirmDelete(int $newspaperId): void
    {
        $this->confirmingDeleteId = $newspaperId;
        $this->dispatch('open-modal', 'confirm-newspaper-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('sajjat.newspapers.modify');

        $newspaper = Newspaper::withCount('payments')->find($this->confirmingDeleteId);
        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-newspaper-deletion');

        if (! $newspaper) {
            return;
        }

        // Payment history is real money records — never silently wiped with the newspaper.
        if ($newspaper->payments_count > 0) {
            $this->notify('This newspaper has recorded payments, so it can\'t be deleted. Mark it inactive instead.', 'error');

            return;
        }

        $newspaper->delete();
        $this->notify('Newspaper deleted.');
    }

    public function startPayment(int $newspaperId): void
    {
        $newspaper = Newspaper::findOrFail($newspaperId);
        $forMonth = $this->periodStart();

        $paid = (float) $newspaper->payments()->whereDate('for_month', $forMonth->toDateString())->sum('amount');
        $outstanding = $newspaper->monthly_amount !== null ? max(0.0, (float) $newspaper->monthly_amount - $paid) : 0;

        $this->payingNewspaperId = $newspaper->id;
        $this->pay_for_month = $forMonth->format('Y-m');
        $this->pay_amount = $outstanding > 0 ? number_format($outstanding, 2, '.', '') : null;
        $this->pay_paid_on = now()->toDateString();
        $this->pay_wallet = 'bkash';
        $this->pay_remarks = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'newspaper-payment-form');
    }

    public function savePayment(): void
    {
        Gate::authorize('sajjat.newspapers.create');

        $validated = $this->validate([
            'pay_for_month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'pay_amount' => ['required', 'numeric', 'min:0.01'],
            'pay_paid_on' => ['required', 'date'],
            'pay_wallet' => ['required', Rule::in(array_keys(SajjatTransaction::WALLETS))],
            'pay_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $newspaper = Newspaper::findOrFail($this->payingNewspaperId);
        $forMonth = Carbon::createFromFormat('Y-m-d', $validated['pay_for_month'].'-01');
        $walletLabel = SajjatTransaction::WALLETS[$validated['pay_wallet']];

        $recorded = DB::transaction(function () use ($validated, $newspaper, $forMonth, $walletLabel): bool {
            // The newspapers are paid out of Sazzad's topped-up money, so
            // the payment can only go through if that wallet can cover it.
            // Checked inside the transaction, right before the expense is
            // written, so two payments can't both spend the same balance.
            SajjatTransaction::query()->where('wallet', $validated['pay_wallet'])->lockForUpdate()->get(['id']);

            if (SajjatTransaction::balances()[$validated['pay_wallet']] + 0.001 < (float) $validated['pay_amount']) {
                return false;
            }

            $expense = SajjatTransaction::create([
                'transaction_date' => $validated['pay_paid_on'],
                'type' => SajjatTransaction::TYPE_EXPENSE,
                'wallet' => $validated['pay_wallet'],
                'amount' => $validated['pay_amount'],
                'description' => 'Newspaper: '.$newspaper->name.' — '.$forMonth->format('M Y'),
                'remarks' => filled($validated['pay_remarks']) ? trim($validated['pay_remarks']) : null,
                'created_by' => auth()->id(),
            ]);

            $newspaper->payments()->create([
                'for_month' => $forMonth->toDateString(),
                'amount' => $validated['pay_amount'],
                'paid_on' => $validated['pay_paid_on'],
                'wallet' => $validated['pay_wallet'],
                'sajjat_transaction_id' => $expense->id,
                'remarks' => filled($validated['pay_remarks']) ? trim($validated['pay_remarks']) : null,
                'created_by' => auth()->id(),
            ]);

            return true;
        });

        if (! $recorded) {
            $available = SajjatTransaction::balances()[$validated['pay_wallet']];
            $this->addError('pay_amount', "Not enough {$walletLabel} balance — only ".number_format($available, 2).' available. Top up Sazzad first, or pay from the other wallet.');

            return;
        }

        $this->payingNewspaperId = null;
        $this->dispatch('close-modal', 'newspaper-payment-form');
        $this->notify('Payment recorded for '.$newspaper->name.'.');
    }

    public function showHistory(int $newspaperId): void
    {
        Newspaper::findOrFail($newspaperId);
        $this->historyNewspaperId = $newspaperId;
        $this->dispatch('open-modal', 'newspaper-history');
    }

    public function confirmPaymentDelete(int $paymentId): void
    {
        $this->confirmingPaymentDeleteId = $paymentId;
        $this->dispatch('open-modal', 'confirm-newspaper-payment-deletion');
    }

    public function deletePayment(): void
    {
        Gate::authorize('sajjat.newspapers.modify');

        $payment = $this->confirmingPaymentDeleteId ? NewspaperPayment::find($this->confirmingPaymentDeleteId) : null;

        if ($payment) {
            // Removing the payment also removes its expense from Sazzad's
            // ledger, which puts the money back in his wallet.
            DB::transaction(function () use ($payment) {
                $payment->delete();
                $payment->sajjatTransaction?->delete();
            });
        }

        $this->confirmingPaymentDeleteId = null;
        $this->dispatch('close-modal', 'confirm-newspaper-payment-deletion');
        $this->notify('Payment deleted.');
    }

    /**
     * Sends the alert straight to the browser's toast stack in this same
     * response, rather than through the session flash the layout polls for —
     * same reasoning as the Sazzad wallet page.
     */
    private function notify(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', message: $message, type: $type);
    }

    /**
     * Every #[Url]-bound filter, appended onto the pagination links so
     * "Next" doesn't reset them.
     *
     * @return array<string, string>
     */
    private function urlQueryState(): array
    {
        return array_filter([
            'month' => $this->period,
            'status' => $this->statusFilter,
            'q' => trim($this->search),
        ], fn ($value) => $value !== '');
    }

    public function with(): array
    {
        $periodStart = $this->periodStart();
        $term = trim($this->search);

        $allRows = Newspaper::query()
            ->where('is_active', $this->statusFilter !== 'inactive')
            ->when($term !== '', fn ($q) => $q->where(fn ($s) => $s
                ->where('name', 'like', "%{$term}%")
                ->orWhere('journalist_name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")))
            ->withSum(['payments as paidForPeriod' => fn ($q) => $q->whereDate('for_month', $periodStart->toDateString())], 'amount')
            ->orderBy('name')
            ->get()
            ->map(function (Newspaper $newspaper) {
                $expected = $newspaper->monthly_amount !== null ? (float) $newspaper->monthly_amount : null;
                $paid = (float) $newspaper->paidForPeriod;

                // With no fixed monthly amount there's nothing to compare
                // against, so any payment that month counts as paid.
                $status = match (true) {
                    $paid <= 0 => 'due',
                    $expected === null, $paid + 0.01 >= $expected => 'paid',
                    default => 'partial',
                };

                return (object) [
                    'newspaper' => $newspaper,
                    'expected' => $expected,
                    'paid' => $paid,
                    'status' => $status,
                ];
            })
            ->when(in_array($this->statusFilter, ['due', 'partial', 'paid'], true), fn ($rows) => $rows->filter(fn ($row) => $row->status === $this->statusFilter))
            ->values();

        $rows = (new LengthAwarePaginator(
            $allRows->forPage($this->getPage(), self::PER_PAGE)->values(),
            $allRows->count(),
            self::PER_PAGE,
            $this->getPage(),
            ['pageName' => 'page', 'path' => $this->paginationPath]
        ))->appends($this->urlQueryState());

        $historyNewspaper = $this->historyNewspaperId ? Newspaper::find($this->historyNewspaperId) : null;

        return [
            'period' => $periodStart,
            'rows' => $rows,
            'totalExpected' => (float) $allRows->sum('expected'),
            'totalPaid' => (float) $allRows->sum('paid'),
            'hasAnyNewspaper' => Newspaper::exists(),
            'historyNewspaper' => $historyNewspaper,
            'historyPayments' => $historyNewspaper
                ? $historyNewspaper->payments()->orderByDesc('for_month')->orderByDesc('id')->get()
                : collect(),
            'wallets' => SajjatTransaction::WALLETS,
            'balances' => SajjatTransaction::balances(),
            'payingNewspaper' => $this->payingNewspaperId ? Newspaper::find($this->payingNewspaperId) : null,
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            @can('sajjat.view')
                <a href="{{ route('sajjat.index') }}" wire:navigate class="rounded-lg px-3 py-1.5 text-sm font-medium text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800">Wallet</a>
            @endcan
            <span class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white">Newspapers</span>
        </div>
        @can('sajjat.newspapers.create')
            <x-primary-button type="button" wire:click="startCreate">
                + Add Newspaper
            </x-primary-button>
        @endcan
    </div>

    <p class="text-xs text-slate-500 dark:text-slate-400">
        Newspapers the client pays every month. Pick a month to see who is paid and who is still due, call or
        WhatsApp the journalist directly, and record each payment.
    </p>

    <div class="flex flex-wrap items-center gap-3">
        <x-text-input type="search" wire:model.live.debounce.400ms="search" placeholder="Search newspaper, journalist or phone…" class="w-full sm:w-64" />
        <x-text-input wire:model.live="period" type="month" class="w-full sm:w-44" />
        <x-select-input wire:model.live="statusFilter" class="w-full sm:w-40">
            <option value="">All statuses</option>
            <option value="due">Due</option>
            <option value="partial">Partially Paid</option>
            <option value="paid">Paid</option>
            <option value="inactive">Inactive newspapers</option>
        </x-select-input>
    </div>

    @if ($rows->isEmpty())
        <x-empty-state
            :title="$hasAnyNewspaper ? 'No newspapers match these filters' : 'No newspapers added yet'"
            :message="$hasAnyNewspaper ? 'Try a different search or status.' : 'Add each newspaper with its journalist and phone number to start tracking monthly payments.'"
        >
            @can('sajjat.newspapers.create')
                <x-slot:action>
                    <x-primary-button type="button" wire:click="startCreate">
                        + Add Newspaper
                    </x-primary-button>
                </x-slot:action>
            @endcan
        </x-empty-state>
    @else
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <table class="w-full min-w-[900px] text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                        <th class="px-4 py-2">Newspaper</th>
                        <th class="px-4 py-2">Journalist</th>
                        <th class="px-4 py-2">Contact</th>
                        <th class="px-4 py-2 text-right">Monthly</th>
                        <th class="px-4 py-2 text-right">Paid ({{ $period->format('M Y') }})</th>
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $newspaper = $row->newspaper; @endphp
                        <tr wire:key="newspaper-{{ $newspaper->id }}" class="border-b border-slate-100 align-top last:border-0 dark:border-slate-700/50">
                            <td class="px-4 py-3">
                                <p class="font-medium text-slate-900 dark:text-white">{{ $newspaper->name }}</p>
                                @if ($newspaper->remarks)
                                    <p class="mt-0.5 max-w-xs whitespace-pre-line text-xs text-slate-500 dark:text-slate-400">{{ $newspaper->remarks }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-700 dark:text-slate-300">{{ $newspaper->journalist_name }}</td>
                            <td class="px-4 py-3">
                                <p class="text-slate-600 dark:text-slate-400">{{ $newspaper->phone }}</p>
                                <div class="mt-1.5 flex items-center gap-2">
                                    <a href="{{ $newspaper->callUrl() }}" class="inline-flex items-center gap-1 rounded-md bg-brand-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-brand-700">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-3.5 w-3.5"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z" /></svg>
                                        Call
                                    </a>
                                    @if ($newspaper->whatsappUrl())
                                        <a href="{{ $newspaper->whatsappUrl() }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-3.5 w-3.5"><path d="M4 20l1.3-4.2A8 8 0 1 1 8.4 19L4 20z" /></svg>
                                            WhatsApp
                                        </a>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right text-slate-800 dark:text-slate-200">
                                {{ $row->expected !== null ? number_format($row->expected, 2) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-400">{{ number_format($row->paid, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                @if ($newspaper->is_active)
                                    <x-badge color="{{ match ($row->status) { 'paid' => 'green', 'partial' => 'brand', default => 'amber' } }}">
                                        {{ match ($row->status) { 'paid' => 'Paid', 'partial' => 'Partially Paid', default => 'Due' } }}
                                    </x-badge>
                                @else
                                    <x-badge color="slate">Inactive</x-badge>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <div class="flex items-center justify-end gap-3 text-xs font-medium">
                                    @can('sajjat.newspapers.create')
                                        <button type="button" wire:click="startPayment({{ $newspaper->id }})" class="text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">Record Payment</button>
                                    @endcan
                                    <button type="button" wire:click="showHistory({{ $newspaper->id }})" class="text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">History</button>
                                    @can('sajjat.newspapers.modify')
                                        <button type="button" wire:click="startEdit({{ $newspaper->id }})" class="text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">Edit</button>
                                        <button type="button" wire:click="confirmDelete({{ $newspaper->id }})" class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">Delete</button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-slate-50 dark:bg-slate-900/50">
                        <td colspan="3" class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-400">Total — {{ $period->format('F Y') }}</td>
                        <td class="px-4 py-2 text-right text-sm font-medium text-slate-600 dark:text-slate-400">{{ number_format($totalExpected, 2) }}</td>
                        <td class="px-4 py-2 text-right text-sm font-medium text-slate-600 dark:text-slate-400">{{ number_format($totalPaid, 2) }}</td>
                        <td></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{ $rows->links('pagination::simple-tailwind') }}
    @endif

    <x-modal name="newspaper-form" focusable>
        <form wire:submit="save" class="space-y-5 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingId ? 'Edit Newspaper' : 'Add Newspaper' }}
            </h2>

            <div>
                <x-input-label for="np_name" value="Newspaper name" />
                <x-text-input wire:model="name" id="np_name" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="np_journalist" value="Journalist (Sangbadik) name" />
                <x-text-input wire:model="journalist_name" id="np_journalist" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('journalist_name')" class="mt-2" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="np_phone" value="Phone number" />
                    <x-text-input wire:model="phone" id="np_phone" type="tel" placeholder="01XXXXXXXXX" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="np_whatsapp" value="WhatsApp number (if available)" />
                    <x-text-input wire:model="whatsapp" id="np_whatsapp" type="tel" placeholder="Optional" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('whatsapp')" class="mt-2" />
                </div>
            </div>

            <div>
                <x-input-label for="np_monthly" value="Monthly amount" />
                <x-text-input wire:model="monthly_amount" id="np_monthly" type="number" step="0.01" min="0" placeholder="Optional — usual monthly payment" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('monthly_amount')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="np_remarks" value="Remarks" />
                <x-textarea-input wire:model="remarks" id="np_remarks" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('remarks')" class="mt-2" />
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                <input type="checkbox" wire:model="is_active" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                Active (still paid every month)
            </label>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-primary-button>{{ $editingId ? 'Save Changes' : 'Add Newspaper' }}</x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="newspaper-payment-form" focusable>
        <form wire:submit="savePayment" class="space-y-5 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                Record Payment{{ $payingNewspaper ? ' — '.$payingNewspaper->name : '' }}
            </h2>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="np_pay_month" value="For month" />
                    <x-text-input wire:model="pay_for_month" id="np_pay_month" type="month" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('pay_for_month')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="np_pay_date" value="Paid on" />
                    <x-text-input wire:model="pay_paid_on" id="np_pay_date" type="date" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('pay_paid_on')" class="mt-2" />
                </div>
            </div>

            <div>
                <x-input-label for="np_pay_amount" value="Amount" />
                <x-text-input wire:model="pay_amount" id="np_pay_amount" type="number" step="0.01" min="0.01" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('pay_amount')" class="mt-2" />
            </div>

            <div>
                <x-input-label value="Pay from Sazzad's" />
                <div class="mt-1 flex flex-wrap gap-4">
                    @foreach ($wallets as $key => $label)
                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                            <input type="radio" wire:model="pay_wallet" value="{{ $key }}" class="text-brand-600 focus:ring-brand-500">
                            {{ $label }}
                            <span class="text-xs {{ $balances[$key] <= 0 ? 'text-red-500' : 'text-slate-400' }}">(balance {{ number_format($balances[$key], 2) }})</span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">The amount is deducted from this wallet. If it isn't enough, top up Sazzad first.</p>
                <x-input-error :messages="$errors->get('pay_wallet')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="np_pay_remarks" value="Remarks" />
                <x-textarea-input wire:model="pay_remarks" id="np_pay_remarks" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('pay_remarks')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-primary-button>Record Payment</x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="newspaper-history" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                Payment History{{ $historyNewspaper ? ' — '.$historyNewspaper->name : '' }}
            </h2>

            <div class="mt-4 max-h-96 space-y-2 overflow-y-auto">
                @forelse ($historyPayments as $payment)
                    <div wire:key="np-payment-{{ $payment->id }}" class="flex items-start justify-between gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <div class="min-w-0 text-sm">
                            <p class="font-medium text-slate-900 dark:text-white">{{ $payment->for_month->format('F Y') }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Paid {{ $payment->paid_on->format('d M Y') }} · from {{ $wallets[$payment->wallet] ?? $payment->wallet }}
                            </p>
                            @if ($payment->remarks)
                                <p class="mt-1 whitespace-pre-line text-xs text-slate-500 dark:text-slate-400">{{ $payment->remarks }}</p>
                            @endif
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ number_format((float) $payment->amount, 2) }}</p>
                            @can('sajjat.newspapers.modify')
                                <button type="button" wire:click="confirmPaymentDelete({{ $payment->id }})" class="mt-1 text-xs font-medium text-red-500 hover:text-red-700 dark:text-red-400">Delete</button>
                            @endcan
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500 dark:text-slate-400">No payments recorded yet.</p>
                @endforelse
            </div>

            <div class="mt-6 flex justify-end">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Close</x-secondary-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="confirm-newspaper-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this newspaper?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                Only a newspaper with no recorded payments can be deleted. If it has payments, mark it inactive instead.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="delete">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="confirm-newspaper-payment-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this payment?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                The month's paid total will be recalculated and the amount goes back into Sazzad's wallet. This cannot be undone.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="deletePayment">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
