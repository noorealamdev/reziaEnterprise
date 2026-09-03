<?php

use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public Employee $employee;

    public string $paymentAmount = '';

    public string $paymentForMonth = '';

    public string $paymentDate = '';

    public ?string $paymentMethod = null;

    public ?string $paymentRemarks = null;

    public ?int $confirmingDeletePaymentId = null;

    public function mount(Employee $employee): void
    {
        $this->employee = $employee;

        // Coming from the Staff Salaries page's "Record Payment" link for a
        // specific month — same ?query convention job-entries.create uses
        // for ?company=, jumps straight into the modal pre-filled for it.
        if (request()->filled('month')) {
            $this->startRecordPayment(request()->string('month')->toString());
        }
    }

    public function startRecordPayment(?string $forMonth = null): void
    {
        $this->paymentAmount = '';
        $this->paymentForMonth = $forMonth ?? now()->format('Y-m');
        $this->paymentDate = now()->toDateString();
        $this->paymentMethod = '';
        $this->paymentRemarks = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'record-salary-payment-form');
    }

    public function recordPayment(): void
    {
        Gate::authorize('salary_payments.create');

        $validated = $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
            'paymentForMonth' => ['required', 'date_format:Y-m'],
            'paymentDate' => ['required', 'date'],
            'paymentMethod' => ['nullable', 'string', 'max:100'],
            'paymentRemarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->employee->salaryPayments()->create([
            'for_month' => Carbon::createFromFormat('Y-m', $validated['paymentForMonth'])->startOfMonth()->toDateString(),
            'amount' => (float) $validated['paymentAmount'],
            'paid_on' => $validated['paymentDate'],
            'payment_method' => $validated['paymentMethod'] ? trim($validated['paymentMethod']) : null,
            'remarks' => $validated['paymentRemarks'] ? trim($validated['paymentRemarks']) : null,
            'created_by' => auth()->id(),
        ]);

        $this->resetPage();
        $this->dispatch('close-modal', 'record-salary-payment-form');
        session()->flash('status', 'Salary payment recorded.');
    }

    public function confirmDeletePayment(int $paymentId): void
    {
        $this->confirmingDeletePaymentId = $paymentId;
        $this->dispatch('open-modal', 'confirm-salary-payment-deletion');
    }

    public function deletePayment(): void
    {
        Gate::authorize('salary_payments.modify');

        if ($this->confirmingDeletePaymentId) {
            $this->employee->salaryPayments()->whereKey($this->confirmingDeletePaymentId)->delete();
        }

        $this->confirmingDeletePaymentId = null;
        $this->dispatch('close-modal', 'confirm-salary-payment-deletion');
    }

    public function with(): array
    {
        $currentMonth = now()->startOfDay()->startOfMonth();
        $expected = (float) $this->employee->monthly_salary;
        $paidThisMonth = (float) $this->employee->salaryPayments()
            ->whereDate('for_month', $currentMonth->toDateString())
            ->sum('amount');
        $balanceThisMonth = max(0, $expected - $paidThisMonth);

        $status = match (true) {
            $paidThisMonth <= 0 => 'due',
            $paidThisMonth + 0.01 >= $expected => 'paid',
            default => 'partial',
        };

        $payments = $this->employee->salaryPayments()
            ->orderByDesc('for_month')
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->simplePaginate(10);

        return [
            'expected' => $expected,
            'paidThisMonth' => $paidThisMonth,
            'balanceThisMonth' => $balanceThisMonth,
            'statusColor' => match ($status) { 'paid' => 'green', 'partial' => 'brand', default => 'amber' },
            'statusLabel' => match ($status) { 'paid' => 'Paid', 'partial' => 'Partially Paid', default => 'Due' },
            'payments' => $payments,
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $employee->name }}</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ $employee->position ?? 'No position set' }}
                @if ($employee->phone)
                    · {{ $employee->phone }}
                @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-badge color="{{ $employee->is_active ? 'green' : 'slate' }}">
                {{ $employee->is_active ? 'Active' : 'Inactive' }}
            </x-badge>

            @can('salary_payments.create')
                <x-primary-button type="button" wire:click="startRecordPayment">
                    Record Payment
                </x-primary-button>
            @endcan

            @can('employees.modify')
                <x-secondary-button :href="route('employees.edit', $employee)" wire:navigate>
                    Edit
                </x-secondary-button>
            @endcan
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
        <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-slate-500 dark:text-slate-400">This Month ({{ now()->format('F Y') }})</span>
            <x-badge color="{{ $statusColor }}">{{ $statusLabel }}</x-badge>
        </div>
        <div class="mt-2 flex items-center justify-between">
            <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Expected</span>
            <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ number_format($expected, 2) }}</span>
        </div>
        <div class="mt-2 flex items-center justify-between">
            <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Paid</span>
            <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ number_format($paidThisMonth, 2) }}</span>
        </div>
        <div class="mt-2 flex items-center justify-between border-t border-slate-100 pt-2 dark:border-slate-700">
            <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Balance</span>
            <span class="text-sm font-semibold {{ $balanceThisMonth > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-800 dark:text-slate-200' }}">{{ number_format($balanceThisMonth, 2) }}</span>
        </div>
    </div>

    @if ($employee->remarks)
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Remarks</h3>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $employee->remarks }}</p>
        </div>
    @endif

    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Payment History</h3>

        @if ($payments->isEmpty())
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No salary payments recorded yet.</p>
        @else
            <div class="mt-3 divide-y divide-slate-100 dark:divide-slate-700/50">
                @foreach ($payments as $payment)
                    <div class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                                {{ number_format((float) $payment->amount, 2) }}
                                <span class="font-normal text-slate-400 dark:text-slate-500">— for {{ $payment->for_month->format('F Y') }}, paid {{ $payment->paid_on->format('d M Y') }}</span>
                            </p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ $payment->payment_method ?: 'No payment method recorded' }}
                            </p>
                            @if ($payment->remarks)
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $payment->remarks }}</p>
                            @endif
                        </div>
                        @can('salary_payments.modify')
                            <button type="button" wire:click="confirmDeletePayment({{ $payment->id }})" class="shrink-0 text-xs font-medium text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                Remove
                            </button>
                        @endcan
                    </div>
                @endforeach
            </div>

            {{ $payments->links('pagination::simple-tailwind') }}
        @endif
    </div>

    <x-modal name="record-salary-payment-form" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Record Salary Payment</h2>

            <div class="mt-4 space-y-4">
                <div>
                    <x-input-label for="paymentAmount" value="Amount" />
                    <x-text-input wire:model="paymentAmount" id="paymentAmount" type="number" step="0.01" min="0.01" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('paymentAmount')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="paymentForMonth" value="For Month" />
                    <x-text-input wire:model="paymentForMonth" id="paymentForMonth" type="month" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('paymentForMonth')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="paymentDate" value="Paid On" />
                    <x-text-input wire:model="paymentDate" id="paymentDate" type="date" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('paymentDate')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="paymentMethod" value="Payment Method" />
                    <x-text-input wire:model="paymentMethod" id="paymentMethod" placeholder="e.g. Cash, Bank Transfer, bKash" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('paymentMethod')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="paymentRemarks" value="Remarks" />
                    <x-textarea-input wire:model="paymentRemarks" id="paymentRemarks" placeholder="Any other detail about this payment" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('paymentRemarks')" class="mt-2" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-primary-button type="button" wire:click="recordPayment">Record Payment</x-primary-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="confirm-salary-payment-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Remove this payment?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                This cannot be undone.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="deletePayment">Remove</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
