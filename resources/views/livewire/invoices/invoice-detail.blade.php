<?php

use App\Models\Invoice;
use App\Support\NumberToWords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public Invoice $invoice;

    public string $deleteBlockedMessage = '';

    public string $paymentAmount = '';

    public string $paymentDate = '';

    public string $checkNumber = '';

    public string $bankName = '';

    public string $paymentDescription = '';

    public $checkImage = null;

    public ?int $confirmingDeletePaymentId = null;

    public $signedCopy = null;

    public bool $confirmingSignedCopyRemoval = false;

    /**
     * Captured once in mount() — a paginator built or re-resolved mid-session
     * would otherwise take its path from request()->url(), which resolves to
     * Livewire's own update endpoint during an AJAX re-render, not this
     * page's real URL.
     */
    public string $paginationPath = '';

    public function mount(Invoice $invoice): void
    {
        $this->invoice = $invoice;
        $this->paginationPath = request()->url();
    }

    /**
     * What's owed on this invoice before any recorded payments are
     * subtracted — bill total, plus VAT if any, minus any pre-invoice
     * advance (e.g. ETP Eid Holiday's company_adv_payment).
     */
    private function amountOwedBeforePayments(): float
    {
        $entries = $this->invoice->jobEntries;
        $total = (float) $entries->sum('bill_amount');
        $vatAmount = $this->invoice->vat_percent ? round($total * (float) $this->invoice->vat_percent / 100, 2) : 0;
        $advancePaid = (float) $entries->sum('company_adv_payment');

        return max(0, $total + $vatAmount - $advancePaid);
    }

    /**
     * Status is derived from what's actually been recorded as paid, never
     * set directly — Due while nothing's paid, Partially Paid while the
     * sum is short of what's owed, Paid once it's fully covered.
     */
    private function refreshInvoiceStatus(): void
    {
        $owed = $this->amountOwedBeforePayments();
        $totalPaid = (float) $this->invoice->payments()->sum('amount');

        if ($totalPaid <= 0) {
            $status = 'due';
            $paidAt = null;
        } elseif ($totalPaid + 0.01 >= $owed) {
            $status = 'paid';
            $paidAt = $this->invoice->payments()->max('paid_on');
        } else {
            $status = 'partial';
            $paidAt = null;
        }

        $this->invoice->update(['status' => $status, 'paid_at' => $paidAt]);
    }

    public function startRecordPayment(): void
    {
        $remaining = max(0, $this->amountOwedBeforePayments() - (float) $this->invoice->payments()->sum('amount'));

        $this->paymentAmount = $remaining > 0 ? rtrim(rtrim(number_format($remaining, 2, '.', ''), '0'), '.') : '';
        $this->paymentDate = now()->toDateString();
        $this->checkNumber = '';
        $this->bankName = '';
        $this->paymentDescription = '';
        $this->checkImage = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'record-payment-form');
    }

    public function recordPayment(): void
    {
        Gate::authorize('payments.create');

        $validated = $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
            'paymentDate' => ['required', 'date'],
            'checkNumber' => ['nullable', 'string', 'max:100'],
            'bankName' => ['nullable', 'string', 'max:255'],
            'paymentDescription' => ['nullable', 'string', 'max:2000'],
            'checkImage' => ['nullable', 'image', 'max:5120'],
        ]);

        $imagePath = $this->checkImage ? $this->checkImage->store('check-screenshots', 'public') : null;

        DB::transaction(function () use ($validated, $imagePath) {
            $this->invoice->payments()->create([
                'amount' => (float) $validated['paymentAmount'],
                'paid_on' => $validated['paymentDate'],
                'check_number' => $validated['checkNumber'] ? trim($validated['checkNumber']) : null,
                'bank_name' => $validated['bankName'] ? trim($validated['bankName']) : null,
                'check_image_path' => $imagePath,
                'description' => $validated['paymentDescription'] ? trim($validated['paymentDescription']) : null,
                'created_by' => auth()->id(),
            ]);

            $this->refreshInvoiceStatus();
        });

        $this->resetPage();
        $this->dispatch('close-modal', 'record-payment-form');
        session()->flash('status', 'Payment recorded.');
    }

    public function confirmDeletePayment(int $paymentId): void
    {
        $this->confirmingDeletePaymentId = $paymentId;
        $this->dispatch('open-modal', 'confirm-payment-deletion');
    }

    public function deletePayment(): void
    {
        Gate::authorize('payments.modify');

        if ($this->confirmingDeletePaymentId) {
            $this->invoice->payments()->whereKey($this->confirmingDeletePaymentId)->delete();
            $this->refreshInvoiceStatus();
        }

        $this->confirmingDeletePaymentId = null;
        $this->dispatch('close-modal', 'confirm-payment-deletion');
    }

    /**
     * The client keeps one printed copy and signs the other as proof of
     * delivery — that signed copy (a photo or scan) is stored here so it
     * can be pulled up if the bill is ever disputed.
     */
    public function uploadSignedCopy(): void
    {
        Gate::authorize('payments.create');

        $this->validate([
            'signedCopy' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        $path = $this->signedCopy->store('signed-bills', 'public');

        $this->invoice->update(['signed_copy_path' => $path]);

        $this->signedCopy = null;
        session()->flash('status', 'Signed bill copy uploaded.');
    }

    public function confirmRemoveSignedCopy(): void
    {
        $this->confirmingSignedCopyRemoval = true;
        $this->dispatch('open-modal', 'confirm-signed-copy-removal');
    }

    public function removeSignedCopy(): void
    {
        Gate::authorize('payments.modify');

        if ($this->invoice->signed_copy_path) {
            Storage::disk('public')->delete($this->invoice->signed_copy_path);
            $this->invoice->update(['signed_copy_path' => null]);
        }

        $this->confirmingSignedCopyRemoval = false;
        $this->dispatch('close-modal', 'confirm-signed-copy-removal');
    }

    public function confirmDelete(): void
    {
        $this->invoice->refresh();

        if ($this->invoice->payments()->exists()) {
            $this->deleteBlockedMessage = 'This invoice has recorded payments and can\'t be deleted. Remove its payments first if it genuinely needs to be removed.';
            $this->dispatch('open-modal', 'invoice-delete-blocked');

            return;
        }

        $this->dispatch('open-modal', 'confirm-invoice-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('invoices.modify');

        $this->invoice->refresh();

        if ($this->invoice->payments()->exists()) {
            return;
        }

        $companyId = $this->invoice->company_id;

        DB::transaction(function () {
            $this->invoice->jobEntries()->update(['invoice_id' => null]);
            $this->invoice->delete();
        });

        session()->flash('status', 'Invoice deleted — its entries are unbilled again.');

        $this->redirect(route('bill-statement.index', ['company' => $companyId]), navigate: true);
    }

    public function with(): array
    {
        $entries = $this->invoice->jobEntries()
            ->with('tiffinDepartment')
            ->orderBy('entry_date')
            ->get();

        // Tiffin is billed as one package per day, not per department or
        // ingredient — one row per date covering every department (Swing,
        // Wash Worker, ...), quantity/rate driven by whichever item
        // actually carries the bill (Egg — same math as the Daily Summary
        // screen), instead of a separate row per department.
        $rows = $entries->first()?->tiffin_department_id
            ? $entries->groupBy(fn ($e) => $e->entry_date->toDateString())
                ->map(function ($batch) {
                    $first = $batch->first();
                    // Only Egg's row actually bills (a fixed rate per
                    // person) — Banana/Bread/any exchange item are cost-
                    // tracking only. Egg's own *stored* quantity includes
                    // the always-sent +5 buffer on top of headcount, so the
                    // billed quantity (and the rate the client actually
                    // agreed to) come from the billing row's own bill_rate
                    // and bill_amount instead — never a division that mixes
                    // the buffered count back in.
                    $billingEntry = $batch->first(fn ($entry) => (float) $entry->bill_amount > 0);
                    $billAmount = (float) $batch->sum('bill_amount');
                    $rate = (float) ($billingEntry->bill_rate ?? 0);
                    $quantity = $rate > 0 ? round($billAmount / $rate, 2) : (float) ($billingEntry->quantity ?? 0);
                    $departments = $batch->pluck('tiffinDepartment.name')->unique()->sort()->implode(', ');
                    $items = $batch->pluck('supply_type')->unique()->sort()->implode(', ');

                    return (object) [
                        'entry_date' => $first->entry_date,
                        'description' => "{$departments} — {$items}",
                        'quantity' => $quantity,
                        'rate' => $rate,
                        'billAmount' => $billAmount,
                    ];
                })
                ->values()
            : $entries->map(fn ($entry) => (object) [
                'entry_date' => $entry->entry_date,
                'description' => collect([
                    $entry->supply_type,
                    $entry->buyer,
                    $entry->style ? "Style {$entry->style}" : null,
                    $entry->floor,
                    $entry->challan_no ? "Challan {$entry->challan_no}" : null,
                ])->filter()->implode(' — '),
                'quantity' => (float) $entry->quantity,
                'rate' => (float) $entry->bill_rate,
                'billAmount' => (float) $entry->bill_amount,
            ])->values();

        $totalCostAmount = $entries->sum('cost_amount');
        $totalProfitAmount = $entries->sum('profit_amount');

        $total = (float) $entries->sum('bill_amount');
        $vatAmount = $this->invoice->vat_percent ? round($total * (float) $this->invoice->vat_percent / 100, 2) : null;
        $grandTotal = $total + ($vatAmount ?? 0);
        $advancePaid = (float) $entries->sum('company_adv_payment');
        $due = $advancePaid > 0 ? $grandTotal - $advancePaid : null;

        $owedBeforePayments = $due ?? $grandTotal;

        // Computed from the full, unpaginated set — $payments below is only
        // ever one page of rows, and the balance owed must reflect every
        // payment ever recorded, not just whichever page is on screen.
        $totalPaidViaPayments = (float) $this->invoice->payments()->sum('amount');

        $payments = $this->invoice->payments()
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->simplePaginate(10)
            ->setPath($this->paginationPath)
            ->through(fn ($payment) => (object) [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'paid_on' => $payment->paid_on,
                'check_number' => $payment->check_number,
                'bank_name' => $payment->bank_name,
                'description' => $payment->description,
                'checkImageUrl' => $payment->check_image_path ? Storage::disk('public')->url($payment->check_image_path) : null,
            ]);
        $balanceDue = max(0, $owedBeforePayments - $totalPaidViaPayments);
        $finalAmount = $balanceDue;

        return [
            'rows' => $rows,
            'unitLabel' => $this->invoice->serviceCategory->unit_label,
            'totalCostAmount' => $totalCostAmount,
            'totalProfitAmount' => $totalProfitAmount,
            'total' => $total,
            'vatAmount' => $vatAmount,
            'grandTotal' => $grandTotal,
            'advancePaid' => $advancePaid > 0 ? $advancePaid : null,
            'due' => $due,
            'payments' => $payments,
            'totalPaidViaPayments' => $totalPaidViaPayments,
            'balanceDue' => $balanceDue,
            'finalAmount' => $finalAmount,
            'amountInWords' => NumberToWords::taka($finalAmount),
            'signedCopyUrl' => $this->invoice->signed_copy_path ? Storage::disk('public')->url($this->invoice->signed_copy_path) : null,
            'signedCopyIsPdf' => str_ends_with((string) $this->invoice->signed_copy_path, '.pdf'),
            'statusColor' => match ($this->invoice->status) {
                'paid' => 'green',
                'partial' => 'brand',
                default => 'amber',
            },
            'statusLabel' => $this->invoice->status === 'partial' ? 'Partially Paid' : ucfirst($this->invoice->status),
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $invoice->invoice_number }}</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ $invoice->serviceCategory->name }} · {{ $invoice->period_start->format('F Y') }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-badge color="{{ $statusColor }}">{{ $statusLabel }}</x-badge>

            @can('payments.create')
                @if ($invoice->status !== 'paid')
                    <x-primary-button type="button" wire:click="startRecordPayment">
                        Record Payment
                    </x-primary-button>
                @endif
            @endcan

            <x-secondary-button type="button" onclick="window.print()">
                Print
            </x-secondary-button>

            @can('invoices.modify')
                <x-danger-button type="button" wire:click="confirmDelete">
                    Delete Invoice
                </x-danger-button>
            @endcan
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800 print:hidden">
        <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Total Cost</span>
            <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ number_format($totalCostAmount, 2) }}</span>
        </div>
        <div class="mt-2 flex items-center justify-between">
            <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Total Profit</span>
            <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ number_format($totalProfitAmount, 2) }}</span>
        </div>
        <div class="mt-2 flex items-center justify-between border-t border-slate-100 pt-2 dark:border-slate-700">
            <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Amount Paid</span>
            <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ number_format($totalPaidViaPayments, 2) }}</span>
        </div>
        <div class="mt-2 flex items-center justify-between">
            <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Balance Due</span>
            <span class="text-sm font-semibold {{ $balanceDue > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-800 dark:text-slate-200' }}">{{ number_format($balanceDue, 2) }}</span>
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800 print:hidden">
        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Payment History</h3>

        @if ($payments->isEmpty())
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No payments recorded yet.</p>
        @else
            <div class="mt-3 divide-y divide-slate-100 dark:divide-slate-700/50">
                @foreach ($payments as $payment)
                    <div class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                                {{ number_format($payment->amount, 2) }}
                                <span class="font-normal text-slate-400 dark:text-slate-500">— {{ $payment->paid_on->format('d M Y') }}</span>
                            </p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                @if ($payment->check_number || $payment->bank_name)
                                    {{ collect([$payment->check_number ? "Check {$payment->check_number}" : null, $payment->bank_name])->filter()->implode(' · ') }}
                                @else
                                    No check details recorded
                                @endif
                            </p>
                            @if ($payment->description)
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $payment->description }}</p>
                            @endif
                            @if ($payment->checkImageUrl)
                                <a href="{{ $payment->checkImageUrl }}" target="_blank" rel="noopener">
                                    <img src="{{ $payment->checkImageUrl }}" alt="Check screenshot" class="mt-2 max-h-24 rounded-lg border border-slate-200 dark:border-slate-700">
                                </a>
                            @endif
                        </div>
                        @can('payments.modify')
                            <button type="button" wire:click="confirmDeletePayment({{ $payment->id }})" class="shrink-0 text-xs font-medium text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                Remove
                            </button>
                        @endcan
                    </div>
                @endforeach
            </div>

            <div class="mt-3">
                {{ $payments->links('pagination::simple-tailwind') }}
            </div>
        @endif
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800 print:hidden">
        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Signed Bill Copy</h3>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Two copies are printed — the client keeps one and signs the other back as proof it was delivered. Keep that signed copy here.</p>

        @if ($signedCopyUrl)
            <div class="mt-3 flex flex-wrap items-center gap-3">
                @if ($signedCopyIsPdf)
                    <a href="{{ $signedCopyUrl }}" target="_blank" rel="noopener" class="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                        PDF
                    </a>
                @else
                    <a href="{{ $signedCopyUrl }}" target="_blank" rel="noopener">
                        <img src="{{ $signedCopyUrl }}" alt="Signed bill copy" class="h-16 w-16 shrink-0 rounded-lg border border-slate-200 object-cover dark:border-slate-700">
                    </a>
                @endif

                <div class="flex flex-col gap-1">
                    <a href="{{ $signedCopyUrl }}" target="_blank" rel="noopener" class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                        View signed copy
                    </a>
                    @can('payments.modify')
                        <button type="button" wire:click="confirmRemoveSignedCopy" class="text-left text-xs font-medium text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                            Remove
                        </button>
                    @endcan
                </div>
            </div>
        @elseif (auth()->user()->can('payments.create'))
            <form wire:submit="uploadSignedCopy" class="mt-3 flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1">
                    <input type="file" wire:model="signedCopy" id="signedCopy" accept="image/*,.pdf" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 dark:text-slate-400 dark:file:bg-slate-700 dark:file:text-slate-200">
                    <div wire:loading wire:target="signedCopy" class="mt-1 text-xs text-slate-400 dark:text-slate-500">Uploading…</div>
                    @if ($signedCopy)
                        @if ($signedCopy->isPreviewable())
                            <img src="{{ $signedCopy->temporaryUrl() }}" alt="Signed bill copy preview" class="mt-2 max-h-24 rounded-lg border border-slate-200 dark:border-slate-700">
                        @else
                            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Selected: {{ $signedCopy->getClientOriginalName() }}</p>
                        @endif
                    @endif
                    <x-input-error :messages="$errors->get('signedCopy')" class="mt-2" />
                </div>
                <x-secondary-button type="submit" class="!text-xs">Upload</x-secondary-button>
            </form>
        @endif
    </div>

    <x-modal name="confirm-signed-copy-removal" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Remove the signed bill copy?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                You can upload another one afterward if needed.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="removeSignedCopy">Remove</x-danger-button>
            </div>
        </div>
    </x-modal>

    {{-- The printable document — what actually gets handed to the factory. --}}
    <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-8 shadow-sm dark:border-slate-800 dark:bg-slate-800 print:!mt-0 print:border-0 print:p-0 print:shadow-none">
        {{-- A faint, oversized watermark behind the whole document — reads as an
        official/original document rather than a plain printout. An <img>/<svg>
        element (not a CSS background-image) so it still prints even when the
        browser's own "background graphics" print setting is off. --}}
        <div class="pointer-events-none absolute inset-0 flex items-center justify-center overflow-hidden select-none">
            <x-application-logo class="h-96 w-96 grayscale opacity-5 print:opacity-10" />
        </div>

        <div class="relative z-10">
        <div class="border-b-2 border-brand-700 pb-4 text-center dark:border-brand-400">
            <div class="flex items-center justify-center gap-3">
                <x-application-logo class="h-12 w-12 shrink-0" />
                <h1 class="text-3xl font-bold uppercase tracking-wide text-brand-800 dark:text-brand-300">{{ config('company.name') }}</h1>
            </div>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ config('company.tagline') }}</p>
            <p class="mt-1 text-sm font-medium text-red-600 dark:text-red-400">{{ config('company.phones') }} · E-mail: {{ config('company.email') }}</p>
            <p class="text-sm text-slate-600 dark:text-slate-400">{{ config('company.address') }}</p>
        </div>

        <div class="mt-6 flex flex-wrap items-start justify-between gap-4 text-sm">
            <div class="space-y-1">
                <p><span class="font-semibold text-slate-900 dark:text-white">Ref:</span> {{ $invoice->invoice_number }}</p>
                <p class="text-slate-700 dark:text-slate-300">To</p>
                <p class="text-slate-700 dark:text-slate-300">The Managing Director</p>
                <p class="text-slate-700 dark:text-slate-300">{{ $invoice->company->name }}</p>
                @if ($invoice->company->address)
                    <p class="text-slate-700 dark:text-slate-300">{{ $invoice->company->address }}</p>
                @endif
            </div>
            <p class="font-semibold text-slate-900 dark:text-white">Date: {{ $invoice->created_at->format('d-M-Y') }}</p>
        </div>

        <p class="mt-4 text-sm font-semibold text-slate-900 dark:text-white">
            Sub: Bill For {{ $invoice->serviceCategory->name }} Month Of {{ $invoice->period_start->format('F Y') }}.
        </p>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[560px] border-collapse text-sm">
                <thead>
                    <tr class="border border-slate-300 bg-slate-100 text-left dark:border-slate-600 dark:bg-slate-900/50">
                        <th class="border border-slate-300 px-3 py-2 dark:border-slate-600">Date</th>
                        <th class="border border-slate-300 px-3 py-2 dark:border-slate-600">Description</th>
                        <th class="border border-slate-300 px-3 py-2 text-right dark:border-slate-600">Quantity</th>
                        <th class="border border-slate-300 px-3 py-2 text-right dark:border-slate-600">Rate</th>
                        <th class="border border-slate-300 px-3 py-2 text-right dark:border-slate-600">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="border border-slate-300 px-3 py-2 text-slate-700 dark:border-slate-600 dark:text-slate-300">{{ $row->entry_date->format('d-M-y') }}</td>
                            <td class="border border-slate-300 px-3 py-2 text-slate-700 dark:border-slate-600 dark:text-slate-300">{{ $row->description }}</td>
                            <td class="border border-slate-300 px-3 py-2 text-right text-slate-700 dark:border-slate-600 dark:text-slate-300">
                                {{ rtrim(rtrim(number_format($row->quantity, 2), '0'), '.') }}{{ $unitLabel ? ' '.$unitLabel : '' }}
                            </td>
                            <td class="border border-slate-300 px-3 py-2 text-right text-slate-700 dark:border-slate-600 dark:text-slate-300">{{ number_format($row->rate, 2) }}</td>
                            <td class="border border-slate-300 px-3 py-2 text-right font-medium text-slate-800 dark:border-slate-600 dark:text-slate-200">{{ number_format($row->billAmount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex justify-end">
            <table class="w-full max-w-sm text-sm">
                <tbody>
                    <tr class="{{ (! $vatAmount && ! $due && ! $totalPaidViaPayments) ? 'font-bold text-base' : '' }}">
                        <td class="py-1 text-slate-700 dark:text-slate-300">Total</td>
                        <td class="py-1 text-right text-slate-900 dark:text-white">{{ number_format($total, 2) }}</td>
                    </tr>
                    @if ($vatAmount)
                        <tr>
                            <td class="py-1 text-slate-700 dark:text-slate-300">VAT ({{ rtrim(rtrim(number_format((float) $invoice->vat_percent, 2), '0'), '.') }}%)</td>
                            <td class="py-1 text-right text-slate-900 dark:text-white">{{ number_format($vatAmount, 2) }}</td>
                        </tr>
                        <tr class="{{ (! $due && ! $totalPaidViaPayments) ? 'font-bold text-base' : '' }}">
                            <td class="py-1 text-slate-700 dark:text-slate-300">Grand Total</td>
                            <td class="py-1 text-right text-slate-900 dark:text-white">{{ number_format($grandTotal, 2) }}</td>
                        </tr>
                    @endif
                    @if ($advancePaid)
                        <tr>
                            <td class="py-1 text-slate-700 dark:text-slate-300">Advance Paid</td>
                            <td class="py-1 text-right text-slate-900 dark:text-white">{{ number_format($advancePaid, 2) }}</td>
                        </tr>
                        <tr class="{{ ! $totalPaidViaPayments ? 'font-bold text-base' : '' }}">
                            <td class="py-1 text-slate-700 dark:text-slate-300">Due</td>
                            <td class="py-1 text-right text-slate-900 dark:text-white">{{ number_format($due, 2) }}</td>
                        </tr>
                    @endif
                    @if ($totalPaidViaPayments)
                        <tr>
                            <td class="py-1 text-slate-700 dark:text-slate-300">Amount Paid</td>
                            <td class="py-1 text-right text-slate-900 dark:text-white">{{ number_format($totalPaidViaPayments, 2) }}</td>
                        </tr>
                        <tr class="border-t border-slate-300 font-bold text-base dark:border-slate-600">
                            <td class="py-1 text-slate-900 dark:text-white">Balance Due</td>
                            <td class="py-1 text-right text-slate-900 dark:text-white">{{ number_format($balanceDue, 2) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        <p class="mt-4 text-sm text-slate-700 dark:text-slate-300">
            In Word: {{ $amountInWords }} Taka Only.
        </p>

        <div class="mt-16 flex items-end justify-between text-sm">
            <div class="text-center">
                <p class="w-48 border-t border-slate-400 pt-1 text-slate-700 dark:border-slate-500 dark:text-slate-300">Client Signature</p>
            </div>
            <div class="text-center">
                <x-application-logo class="mx-auto h-14 w-14 rounded-full border border-slate-300 dark:border-slate-600" />
                <p class="mt-2 font-semibold text-slate-900 dark:text-white">{{ config('company.name') }}</p>
                <p class="mt-6 w-48 border-t border-slate-400 pt-1 text-slate-700 dark:border-slate-500 dark:text-slate-300">Authorised</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">Thanking By</p>
            </div>
        </div>
        </div>
    </div>

    <x-modal name="record-payment-form" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Record Payment</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                If this was paid by check, record the details below. Check details are optional.
            </p>

            <div class="mt-4 space-y-4">
                <div>
                    <x-input-label for="paymentAmount" value="Amount" />
                    <x-text-input wire:model="paymentAmount" id="paymentAmount" type="number" step="0.01" min="0.01" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('paymentAmount')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="paymentDate" value="Date" />
                    <x-text-input wire:model="paymentDate" id="paymentDate" type="date" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('paymentDate')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="checkNumber" value="Check Number" />
                    <x-text-input wire:model="checkNumber" id="checkNumber" placeholder="e.g. 0451236" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('checkNumber')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="bankName" value="Bank Name" />
                    <x-text-input wire:model="bankName" id="bankName" placeholder="e.g. Dutch-Bangla Bank" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('bankName')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="checkImage" value="Check Screenshot" />
                    <input type="file" wire:model="checkImage" id="checkImage" accept="image/*" class="mt-1 block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 dark:text-slate-400 dark:file:bg-slate-700 dark:file:text-slate-200">
                    <div wire:loading wire:target="checkImage" class="mt-1 text-xs text-slate-400 dark:text-slate-500">Uploading…</div>
                    @if ($checkImage && $checkImage->isPreviewable())
                        <img src="{{ $checkImage->temporaryUrl() }}" alt="Check screenshot preview" class="mt-2 max-h-32 rounded-lg border border-slate-200 dark:border-slate-700">
                    @endif
                    <x-input-error :messages="$errors->get('checkImage')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="paymentDescription" value="Description" />
                    <x-textarea-input wire:model="paymentDescription" id="paymentDescription" placeholder="Any other detail about this payment" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('paymentDescription')" class="mt-2" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-primary-button type="button" wire:click="recordPayment">Record Payment</x-primary-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="confirm-payment-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Remove this payment?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                The invoice's status will be recalculated from what's left. This cannot be undone.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="deletePayment">Remove</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="confirm-invoice-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this invoice?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                Its job entries will become unbilled and available for a future invoice. This cannot be undone.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="delete">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="invoice-delete-blocked" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Can't delete this invoice</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $deleteBlockedMessage }}</p>
            <div class="mt-6 flex justify-end">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Close</x-secondary-button>
            </div>
        </div>
    </x-modal>
</div>
