<?php

use App\Models\EggInvoice;
use App\Support\NumberToWords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public EggInvoice $bill;

    public string $paymentAmount = '';

    public string $paymentDate = '';

    public string $paymentType = 'cash';

    public string $checkNumber = '';

    public string $bankName = '';

    public string $paymentDescription = '';

    public $checkImage = null;

    public ?int $confirmingDeletePaymentId = null;

    public $signedCopy = null;

    public function mount(EggInvoice $bill): void
    {
        $this->bill = $bill;
    }

    public function startRecordPayment(): void
    {
        $remaining = $this->bill->balanceDue;

        $this->paymentAmount = $remaining > 0 ? rtrim(rtrim(number_format($remaining, 2, '.', ''), '0'), '.') : '';
        $this->paymentDate = now()->toDateString();
        $this->paymentType = 'cash';
        $this->checkNumber = '';
        $this->bankName = '';
        $this->paymentDescription = '';
        $this->checkImage = null;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'egg-bill-payment-form');
    }

    public function recordPayment(): void
    {
        Gate::authorize('egg_sales.create');

        $validated = $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01', 'max:'.$this->bill->balanceDue],
            'paymentDate' => ['required', 'date'],
            'paymentType' => ['required', Rule::in(['cash', 'check'])],
            'checkNumber' => ['nullable', 'string', 'max:100'],
            'bankName' => ['nullable', 'string', 'max:255'],
            'paymentDescription' => ['nullable', 'string', 'max:2000'],
            'checkImage' => ['nullable', 'image', 'max:5120'],
        ], [
            'paymentAmount.max' => 'The balance due on this bill is only '.number_format($this->bill->balanceDue, 2).'.',
        ], ['paymentAmount' => 'amount', 'paymentDate' => 'date']);

        $isCheck = $validated['paymentType'] === 'check';
        $imagePath = ($isCheck && $this->checkImage) ? $this->checkImage->store('check-screenshots', 'public') : null;

        DB::transaction(function () use ($validated, $isCheck, $imagePath) {
            $this->bill->payments()->create([
                'amount' => (float) $validated['paymentAmount'],
                'paid_on' => $validated['paymentDate'],
                'type' => $validated['paymentType'],
                'check_number' => ($isCheck && $validated['checkNumber']) ? trim($validated['checkNumber']) : null,
                'bank_name' => ($isCheck && $validated['bankName']) ? trim($validated['bankName']) : null,
                'check_image_path' => $imagePath,
                'description' => $validated['paymentDescription'] ? trim($validated['paymentDescription']) : null,
                'created_by' => auth()->id(),
            ]);

            $this->bill->refreshStatus();
        });

        $this->dispatch('close-modal', 'egg-bill-payment-form');
        $this->dispatch('toast', message: 'Payment recorded.', type: 'success');
    }

    public function confirmDeletePayment(int $paymentId): void
    {
        $this->confirmingDeletePaymentId = $paymentId;
        $this->dispatch('open-modal', 'confirm-egg-payment-deletion');
    }

    public function deletePayment(): void
    {
        Gate::authorize('egg_sales.modify');

        if ($this->confirmingDeletePaymentId) {
            $this->bill->payments()->whereKey($this->confirmingDeletePaymentId)->delete();
            $this->bill->refreshStatus();
        }

        $this->confirmingDeletePaymentId = null;
        $this->dispatch('close-modal', 'confirm-egg-payment-deletion');
        $this->dispatch('toast', message: 'Payment removed.', type: 'success');
    }

    public function uploadSignedCopy(): void
    {
        Gate::authorize('egg_sales.create');

        $this->validate([
            'signedCopy' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        $this->bill->update(['signed_copy_path' => $this->signedCopy->store('signed-bills', 'public')]);
        $this->signedCopy = null;
        $this->dispatch('toast', message: 'Signed bill copy uploaded.', type: 'success');
    }

    public function confirmRemoveSignedCopy(): void
    {
        $this->dispatch('open-modal', 'confirm-egg-signed-copy-removal');
    }

    public function removeSignedCopy(): void
    {
        Gate::authorize('egg_sales.modify');

        if ($this->bill->signed_copy_path) {
            Storage::disk('public')->delete($this->bill->signed_copy_path);
            $this->bill->update(['signed_copy_path' => null]);
        }

        $this->dispatch('close-modal', 'confirm-egg-signed-copy-removal');
    }

    public function confirmDelete(): void
    {
        $this->dispatch('open-modal', $this->bill->payments()->exists() ? 'egg-bill-delete-blocked' : 'confirm-egg-bill-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('egg_sales.modify');

        if ($this->bill->payments()->exists()) {
            return;
        }

        DB::transaction(function () {
            $this->bill->sales()->update(['egg_invoice_id' => null]);
            $this->bill->delete();
        });

        session()->flash('status', 'Bill deleted. Its sales are ready to bill again.');
        $this->redirect(route('egg-sales.index', ['view' => 'bills']), navigate: true);
    }

    public function with(): array
    {
        $this->bill->refresh();
        $total = $this->bill->total;
        $paid = $this->bill->amountPaid;
        $balance = max(0.0, round($total - $paid, 2));

        return [
            'sales' => $this->bill->sales()->orderBy('sale_date')->orderBy('id')->get(),
            'payments' => $this->bill->payments()->orderByDesc('paid_on')->orderByDesc('id')->get(),
            'total' => $total,
            'paid' => $paid,
            'balance' => $balance,
            'amountInWords' => NumberToWords::takaWithPaisa($balance),
            'signedCopyUrl' => $this->bill->signed_copy_path ? Storage::disk('public')->url($this->bill->signed_copy_path) : null,
            'signedCopyIsPdf' => str_ends_with((string) $this->bill->signed_copy_path, '.pdf'),
            'statusColor' => match ($this->bill->status) {
                'paid' => 'green',
                'partial' => 'brand',
                default => 'amber',
            },
            'statusLabel' => $this->bill->status === 'partial' ? 'Partially Paid' : ucfirst($this->bill->status),
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
        <div>
            <a href="{{ route('egg-sales.index', ['view' => 'bills']) }}" wire:navigate class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">&larr; All Egg Bills</a>
            <h2 class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ $bill->buyer->name }} · {{ $bill->periodLabel }}</h2>
            @if ($bill->remarks)
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $bill->remarks }}</p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-badge :color="$statusColor">{{ $statusLabel }}</x-badge>

            @can('egg_sales.create')
                @if ($bill->status !== 'paid')
                    <x-primary-button type="button" wire:click="startRecordPayment">Record Payment</x-primary-button>
                @endif
            @endcan

            <x-secondary-button type="button" onclick="window.print()">Print</x-secondary-button>

            @can('egg_sales.modify')
                <x-danger-button type="button" wire:click="confirmDelete">Delete Bill</x-danger-button>
            @endcan
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 print:hidden">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Bill total</p>
            <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($total, 2) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Amount paid</p>
            <p class="mt-1 text-lg font-semibold text-emerald-700 dark:text-emerald-400">{{ number_format($paid, 2) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <p class="text-xs text-slate-500 dark:text-slate-400">Balance due</p>
            <p class="mt-1 text-lg font-semibold {{ $balance > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">{{ number_format($balance, 2) }}</p>
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800 print:hidden">
        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Payment History</h3>

        @if ($payments->isEmpty())
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No payments recorded yet.</p>
        @else
            <div class="mt-3 divide-y divide-slate-100 dark:divide-slate-700/50">
                @foreach ($payments as $payment)
                    <div wire:key="payment-{{ $payment->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                                {{ number_format((float) $payment->amount, 2) }}
                                <span class="font-normal text-slate-500 dark:text-slate-400">· {{ $payment->paid_on->format('d M Y') }}</span>
                            </p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                @if ($payment->type === 'check')
                                    Check{{ $payment->check_number ? ' '.$payment->check_number : '' }}{{ $payment->bank_name ? ' · '.$payment->bank_name : '' }}
                                @else
                                    Cash
                                @endif
                            </p>
                            @if ($payment->description)
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $payment->description }}</p>
                            @endif
                            @if ($payment->check_image_path)
                                <a href="{{ Storage::disk('public')->url($payment->check_image_path) }}" target="_blank" rel="noopener">
                                    <img src="{{ Storage::disk('public')->url($payment->check_image_path) }}" alt="Check screenshot" class="mt-2 max-h-24 rounded-lg border border-slate-200 dark:border-slate-700">
                                </a>
                            @endif
                        </div>
                        @can('egg_sales.modify')
                            <button type="button" wire:click="confirmDeletePayment({{ $payment->id }})" class="shrink-0 text-xs font-medium text-red-600 hover:text-red-700 dark:text-red-400">Remove</button>
                        @endcan
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800 print:hidden">
        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Signed Bill Copy</h3>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Keep the copy the buyer signed here, as proof the bill was delivered.</p>

        @if ($signedCopyUrl)
            <div class="mt-3 flex flex-wrap items-center gap-3">
                @if ($signedCopyIsPdf)
                    <a href="{{ $signedCopyUrl }}" target="_blank" rel="noopener" class="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">PDF</a>
                @else
                    <a href="{{ $signedCopyUrl }}" target="_blank" rel="noopener">
                        <img src="{{ $signedCopyUrl }}" alt="Signed bill copy" class="h-16 w-16 shrink-0 rounded-lg border border-slate-200 object-cover dark:border-slate-700">
                    </a>
                @endif
                <div class="flex flex-col gap-1">
                    <a href="{{ $signedCopyUrl }}" target="_blank" rel="noopener" class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">View signed copy</a>
                    @can('egg_sales.modify')
                        <button type="button" wire:click="confirmRemoveSignedCopy" class="text-left text-xs font-medium text-red-600 hover:text-red-700 dark:text-red-400">Remove</button>
                    @endcan
                </div>
            </div>
        @elsecan('egg_sales.create')
            <form wire:submit="uploadSignedCopy" class="mt-3 flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1">
                    <input type="file" wire:model="signedCopy" id="signedCopy" aria-label="Signed bill copy" accept="image/*,.pdf" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 dark:text-slate-400 dark:file:bg-slate-700 dark:file:text-slate-200">
                    <div wire:loading wire:target="signedCopy" class="mt-1 text-xs text-slate-500 dark:text-slate-400">Uploading…</div>
                    <x-input-error :messages="$errors->get('signedCopy')" class="mt-2" />
                </div>
                <x-secondary-button type="submit">Upload</x-secondary-button>
            </form>
        @endif
    </div>

    {{-- The printable document handed to the buyer. --}}
    <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-8 shadow-sm dark:border-slate-800 dark:bg-slate-800 print:!mt-0 print:border-0 print:p-0 print:shadow-none">
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
                    <p><span class="font-semibold text-slate-900 dark:text-white">Ref:</span> {{ $bill->invoice_number }}</p>
                    <p class="text-slate-700 dark:text-slate-300">To</p>
                    <p class="font-medium text-slate-900 dark:text-white">{{ $bill->buyer->name }}</p>
                    @if ($bill->buyer->address)
                        <p class="text-slate-700 dark:text-slate-300">{{ $bill->buyer->address }}</p>
                    @endif
                    @if ($bill->buyer->phone)
                        <p class="text-slate-700 dark:text-slate-300">{{ $bill->buyer->phone }}</p>
                    @endif
                </div>
                <p class="font-semibold text-slate-900 dark:text-white">Date: {{ $bill->created_at->format('d-M-Y') }}</p>
            </div>

            <p class="mt-4 text-sm font-semibold text-slate-900 dark:text-white">Sub: Bill For Egg Supply {{ $bill->isWholeMonth ? 'Month' : 'Period' }} Of {{ $bill->periodLabel }}.</p>

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
                        @foreach ($sales as $sale)
                            <tr wire:key="bill-sale-{{ $sale->id }}">
                                <td class="border border-slate-300 px-3 py-2 text-slate-700 dark:border-slate-600 dark:text-slate-300">{{ $sale->sale_date->format('d-M-y') }}</td>
                                <td class="border border-slate-300 px-3 py-2 text-slate-700 dark:border-slate-600 dark:text-slate-300">Egg{{ $sale->remarks ? ' — '.$sale->remarks : '' }}</td>
                                <td class="border border-slate-300 px-3 py-2 text-right text-slate-700 dark:border-slate-600 dark:text-slate-300">{{ rtrim(rtrim(number_format((float) $sale->quantity, 2), '0'), '.') }}</td>
                                <td class="border border-slate-300 px-3 py-2 text-right text-slate-700 dark:border-slate-600 dark:text-slate-300">{{ number_format((float) $sale->sale_rate, 2) }}</td>
                                <td class="border border-slate-300 px-3 py-2 text-right font-medium text-slate-800 dark:border-slate-600 dark:text-slate-200">{{ number_format((float) $sale->sale_amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex justify-end">
                <table class="w-full max-w-sm text-sm">
                    <tbody>
                        <tr class="{{ $paid > 0 ? '' : 'text-base font-bold' }}">
                            <td class="py-1 text-slate-700 dark:text-slate-300">Total</td>
                            <td class="py-1 text-right text-slate-900 dark:text-white">{{ number_format($total, 2) }}</td>
                        </tr>
                        @if ($paid > 0)
                            <tr>
                                <td class="py-1 text-slate-700 dark:text-slate-300">Amount Paid</td>
                                <td class="py-1 text-right text-slate-900 dark:text-white">{{ number_format($paid, 2) }}</td>
                            </tr>
                            <tr class="border-t border-slate-300 text-base font-bold dark:border-slate-600">
                                <td class="py-1 text-slate-900 dark:text-white">Balance Due</td>
                                <td class="py-1 text-right text-slate-900 dark:text-white">{{ number_format($balance, 2) }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            <p class="mt-4 text-sm text-slate-700 dark:text-slate-300">In Word: {{ $amountInWords }} Only.</p>

            <div class="mt-16 flex items-end justify-between text-sm">
                <div class="text-center">
                    <p class="w-48 border-t border-slate-400 pt-1 text-slate-700 dark:border-slate-500 dark:text-slate-300">Buyer Signature</p>
                </div>
                <div class="text-center">
                    <x-application-logo class="mx-auto h-14 w-14 rounded-full border border-slate-300 dark:border-slate-600" />
                    <p class="mt-2 font-semibold text-slate-900 dark:text-white">{{ config('company.name') }}</p>
                    <p class="mt-6 w-48 border-t border-slate-400 pt-1 text-slate-700 dark:border-slate-500 dark:text-slate-300">Authorised</p>
                </div>
            </div>
        </div>
    </div>

    @can('egg_sales.create')
    <x-modal name="egg-bill-payment-form" focusable>
        <form wire:submit="recordPayment" class="space-y-4 p-6">
            <div>
                <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Record Payment</h2>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Balance due: {{ number_format($balance, 2) }}. Enter less for a partial payment.</p>
            </div>

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
                <x-input-label for="paymentType" value="Payment Type" />
                <x-select-input wire:model.live="paymentType" id="paymentType" class="mt-1 block w-full">
                    <option value="cash">Cash</option>
                    <option value="check">Check</option>
                </x-select-input>
                <x-input-error :messages="$errors->get('paymentType')" class="mt-2" />
            </div>

            @if ($paymentType === 'check')
                <div>
                    <x-input-label for="checkNumber" value="Check Number (optional)" />
                    <x-text-input wire:model="checkNumber" id="checkNumber" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('checkNumber')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="bankName" value="Bank Name (optional)" />
                    <x-text-input wire:model="bankName" id="bankName" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('bankName')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="checkImage" value="Check Screenshot (optional)" />
                    <input type="file" wire:model="checkImage" id="checkImage" accept="image/*" class="mt-1 block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 dark:text-slate-400 dark:file:bg-slate-700 dark:file:text-slate-200">
                    <div wire:loading wire:target="checkImage" class="mt-1 text-xs text-slate-500 dark:text-slate-400">Uploading…</div>
                    <x-input-error :messages="$errors->get('checkImage')" class="mt-2" />
                </div>
            @endif

            <div>
                <x-input-label for="paymentDescription" value="Note (optional)" />
                <x-textarea-input wire:model="paymentDescription" id="paymentDescription" rows="2" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('paymentDescription')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-primary-button>Record Payment</x-primary-button>
            </div>
        </form>
    </x-modal>
    @endcan

    @can('egg_sales.modify')
    <x-modal name="confirm-egg-payment-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Remove this payment?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">The bill's status will be recalculated. This cannot be undone.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="deletePayment">Remove</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="confirm-egg-signed-copy-removal" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Remove the signed bill copy?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">You can upload another one afterward.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="removeSignedCopy">Remove</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="confirm-egg-bill-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this bill?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Its sales will go back to Ready to Bill. The sales themselves are not deleted.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="delete">Delete Bill</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="egg-bill-delete-blocked" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Can't delete this bill</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">This bill has recorded payments. Remove its payments first if it really needs to be deleted.</p>
            <div class="mt-6 flex justify-end">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Close</x-secondary-button>
            </div>
        </div>
    </x-modal>
    @endcan
</div>
