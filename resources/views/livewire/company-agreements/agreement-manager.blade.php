<?php

use App\Models\Company;
use App\Models\CompanyAgreement;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'company', history: true)]
    public string $companyFilter = '';

    /** '', 'expiring', 'expired', or 'active' (not expiring soon, not expired). */
    #[Url(as: 'status', history: true)]
    public string $statusFilter = '';

    public ?int $editingId = null;

    public ?int $company_id = null;

    public string $title = '';

    public string $start_date = '';

    public string $end_date = '';

    public ?string $remarks = null;

    public ?string $existingDocumentPath = null;

    public $documentFile = null;

    public bool $removeDocument = false;

    public ?int $confirmingDeleteId = null;

    /**
     * Captured once in mount() — a manually-built LengthAwarePaginator needs
     * an explicit 'path', and request()->url() would otherwise resolve to
     * Livewire's own update endpoint on any re-render triggered by a filter
     * change, not this page's real URL.
     */
    public string $paginationPath = '';

    public function mount(): void
    {
        $this->paginationPath = request()->url();
    }

    public function updatingCompanyFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, string>
     */
    private function urlQueryState(): array
    {
        return array_filter([
            'company' => $this->companyFilter,
            'status' => $this->statusFilter,
        ], fn ($value) => $value !== '');
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->company_id = $this->companyFilter ? (int) $this->companyFilter : null;
        $this->title = '';
        $this->start_date = now()->toDateString();
        $this->end_date = '';
        $this->remarks = null;
        $this->existingDocumentPath = null;
        $this->documentFile = null;
        $this->removeDocument = false;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'company-agreement-form');
    }

    public function startEdit(int $agreementId): void
    {
        $agreement = CompanyAgreement::findOrFail($agreementId);
        $this->editingId = $agreement->id;
        $this->company_id = $agreement->company_id;
        $this->title = $agreement->title;
        $this->start_date = $agreement->start_date?->format('Y-m-d') ?? '';
        $this->end_date = $agreement->end_date->format('Y-m-d');
        $this->remarks = $agreement->remarks;
        $this->existingDocumentPath = $agreement->document_path;
        $this->documentFile = null;
        $this->removeDocument = false;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'company-agreement-form');
    }

    public function clearDocument(): void
    {
        $this->existingDocumentPath = null;
        $this->removeDocument = true;
    }

    public function save(): void
    {
        Gate::authorize($this->editingId ? 'company_agreements.modify' : 'company_agreements.create');

        $validated = $this->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'title' => ['required', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'documentFile' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['title'] = trim($validated['title']);
        $validated['start_date'] = $validated['start_date'] ?: null;
        $validated['remarks'] = $validated['remarks'] ? trim($validated['remarks']) : null;

        $existingAgreement = $this->editingId ? CompanyAgreement::find($this->editingId) : null;

        // Renewing (or otherwise changing) the deadline clears any prior
        // alert so the resend clock restarts against the new date — an old
        // "already alerted" timestamp must never suppress a fresh deadline.
        if ($existingAgreement && $existingAgreement->end_date->format('Y-m-d') !== $validated['end_date']) {
            $validated['last_alerted_at'] = null;
        }

        unset($validated['documentFile']);

        if ($this->documentFile) {
            if ($existingAgreement?->document_path) {
                Storage::disk('public')->delete($existingAgreement->document_path);
            }
            $validated['document_path'] = $this->documentFile->store('company-agreements', 'public');
        } elseif ($this->removeDocument) {
            if ($existingAgreement?->document_path) {
                Storage::disk('public')->delete($existingAgreement->document_path);
            }
            $validated['document_path'] = null;
        }

        if ($this->editingId) {
            CompanyAgreement::whereKey($this->editingId)->update($validated);
        } else {
            $validated['created_by'] = auth()->id();
            CompanyAgreement::create($validated);
        }

        $this->documentFile = null;
        $this->removeDocument = false;
        $this->dispatch('close-modal', 'company-agreement-form');
        session()->flash('status', $this->editingId ? 'Agreement updated.' : 'Agreement recorded.');
    }

    public function confirmDelete(int $agreementId): void
    {
        $this->confirmingDeleteId = $agreementId;
        $this->dispatch('open-modal', 'confirm-company-agreement-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('company_agreements.modify');

        if ($this->confirmingDeleteId) {
            $agreement = CompanyAgreement::find($this->confirmingDeleteId);

            if ($agreement?->document_path) {
                Storage::disk('public')->delete($agreement->document_path);
            }

            $agreement?->delete();
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-company-agreement-deletion');
        session()->flash('status', 'Agreement deleted.');
    }

    public function with(): array
    {
        $today = now()->toDateString();
        $alertDays = (int) config('reports.agreement_deadline_alert_days');
        $alertCutoff = now()->addDays($alertDays)->toDateString();

        $agreementsQuery = CompanyAgreement::with('company')
            ->when($this->companyFilter, fn ($query) => $query->where('company_id', $this->companyFilter))
            ->when($this->statusFilter === 'expired', fn ($query) => $query->whereDate('end_date', '<', $today))
            ->when($this->statusFilter === 'expiring', fn ($query) => $query->whereDate('end_date', '>=', $today)->whereDate('end_date', '<=', $alertCutoff))
            ->when($this->statusFilter === 'active', fn ($query) => $query->whereDate('end_date', '>', $alertCutoff));

        return [
            'companies' => Company::orderBy('name')->get(),
            'agreements' => $agreementsQuery->clone()
                ->orderBy('end_date')
                ->orderByDesc('id')
                ->simplePaginate(10)
                ->setPath($this->paginationPath)
                ->appends($this->urlQueryState()),
            'expiringCount' => CompanyAgreement::when($this->companyFilter, fn ($query) => $query->where('company_id', $this->companyFilter))
                ->whereDate('end_date', '>=', $today)
                ->whereDate('end_date', '<=', $alertCutoff)
                ->count(),
            'expiredCount' => CompanyAgreement::when($this->companyFilter, fn ($query) => $query->where('company_id', $this->companyFilter))
                ->whereDate('end_date', '<', $today)
                ->count(),
            'alertDays' => $alertDays,
            'existingDocumentUrl' => $this->existingDocumentPath ? Storage::disk('public')->url($this->existingDocumentPath) : null,
            'existingDocumentIsPdf' => str_ends_with((string) $this->existingDocumentPath, '.pdf'),
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Company Agreements</h2>
        @can('company_agreements.create')
            <x-primary-button type="button" wire:click="startCreate">
                + Add Agreement
            </x-primary-button>
        @endcan
    </div>

    <p class="text-xs text-slate-500 dark:text-slate-400">
        Every agreement Rezia Enterprise has with a company, and when it's due to expire — Super Admins
        get an email once a deadline is within {{ $alertDays }} days, and every {{ $alertDays }} days'
        worth of "Expiring Soon" agreements can also be seen right here.
    </p>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-sm dark:border-amber-800 dark:bg-amber-900/20">
            <p class="text-xs text-amber-700 dark:text-amber-300">Expiring Soon (within {{ $alertDays }} days)</p>
            <p class="mt-1 text-lg font-semibold text-amber-900 dark:text-white">{{ $expiringCount }}</p>
        </div>
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 shadow-sm dark:border-red-800 dark:bg-red-900/20">
            <p class="text-xs text-red-700 dark:text-red-300">Expired</p>
            <p class="mt-1 text-lg font-semibold text-red-900 dark:text-white">{{ $expiredCount }}</p>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <x-select-input wire:model.live="companyFilter" class="w-full sm:w-56">
            <option value="">All companies</option>
            @foreach ($companies as $company)
                <option value="{{ $company->id }}">{{ $company->name }}</option>
            @endforeach
        </x-select-input>

        <x-select-input wire:model.live="statusFilter" class="w-full sm:w-48">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="expiring">Expiring Soon</option>
            <option value="expired">Expired</option>
        </x-select-input>
    </div>

    @forelse ($agreements as $agreement)
        @php
            $daysLeft = (int) round(now()->diffInDays($agreement->end_date, absolute: true));
        @endphp
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $agreement->title }}</h3>
                        @if ($agreement->is_expired)
                            <x-badge color="red">Expired {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }} ago</x-badge>
                        @elseif ($daysLeft <= $alertDays)
                            <x-badge color="amber">Expires in {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }}</x-badge>
                        @else
                            <x-badge color="green">Active</x-badge>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ $agreement->company->name }}
                        @if ($agreement->start_date)
                            · {{ $agreement->start_date->format('d M Y') }} &ndash; {{ $agreement->end_date->format('d M Y') }}
                        @else
                            · Ends {{ $agreement->end_date->format('d M Y') }}
                        @endif
                    </p>
                    @if ($agreement->document_url)
                        <a href="{{ $agreement->document_url }}" target="_blank" rel="noopener" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h1M6 4h12a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z" /></svg>
                            {{ $agreement->document_is_pdf ? 'View Document (PDF)' : 'View Document' }}
                        </a>
                    @endif
                    @if ($agreement->remarks)
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $agreement->remarks }}</p>
                    @endif
                </div>
            </div>

            @can('company_agreements.modify')
                <div class="mt-4 flex items-center gap-3">
                    <x-secondary-button type="button" wire:click="startEdit({{ $agreement->id }})">
                        Edit
                    </x-secondary-button>
                    <x-danger-button type="button" wire:click="confirmDelete({{ $agreement->id }})">
                        Delete
                    </x-danger-button>
                </div>
            @endcan
        </div>
    @empty
        <x-empty-state
            title="No agreements recorded yet"
            message="Add a company agreement to start tracking its deadline."
        />
    @endforelse

    {{ $agreements->links('pagination::simple-tailwind') }}

    <x-modal name="company-agreement-form" focusable>
        <form wire:submit="save" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingId ? 'Edit Agreement' : 'Add Agreement' }}
            </h2>

            <div>
                <x-input-label for="agreement_company" value="Company" />
                <x-select-input wire:model="company_id" id="agreement_company" class="mt-1 block w-full" required>
                    <option value="">Select a company…</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('company_id')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="agreement_title" value="Title" />
                <x-text-input wire:model="title" id="agreement_title" placeholder="e.g. Service Agreement 2026-2027" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('title')" class="mt-2" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="agreement_start_date" value="Start Date (optional)" />
                    <x-text-input wire:model="start_date" id="agreement_start_date" type="date" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('start_date')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="agreement_end_date" value="Deadline / End Date" />
                    <x-text-input wire:model="end_date" id="agreement_end_date" type="date" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('end_date')" class="mt-2" />
                </div>
            </div>

            <div>
                <x-input-label for="agreement_document" value="Agreement Document" />

                @if ($existingDocumentUrl)
                    <div class="mt-1 flex items-center gap-3">
                        @if ($existingDocumentIsPdf)
                            <a href="{{ $existingDocumentUrl }}" target="_blank" rel="noopener" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                                PDF
                            </a>
                        @else
                            <a href="{{ $existingDocumentUrl }}" target="_blank" rel="noopener">
                                <img src="{{ $existingDocumentUrl }}" alt="Agreement document" class="h-12 w-12 shrink-0 rounded-lg border border-slate-200 object-cover dark:border-slate-700">
                            </a>
                        @endif
                        <a href="{{ $existingDocumentUrl }}" target="_blank" rel="noopener" class="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                            View current document
                        </a>
                        <button type="button" wire:click="clearDocument" class="text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                            Remove
                        </button>
                    </div>
                @endif

                <input type="file" wire:model="documentFile" id="agreement_document" accept="image/*,.pdf" class="mt-2 block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 dark:text-slate-400 dark:file:bg-slate-700 dark:file:text-slate-200">
                <div wire:loading wire:target="documentFile" class="mt-1 text-xs text-slate-400 dark:text-slate-500">Uploading…</div>
                @if ($documentFile && $documentFile->isPreviewable())
                    <img src="{{ $documentFile->temporaryUrl() }}" alt="Agreement document preview" class="mt-2 max-h-24 rounded-lg border border-slate-200 dark:border-slate-700">
                @endif
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">JPG, PNG or PDF, up to 10MB.{{ $existingDocumentUrl ? ' Choosing a new file replaces the one above.' : '' }}</p>
                <x-input-error :messages="$errors->get('documentFile')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="agreement_remarks" value="Remarks" />
                <x-textarea-input wire:model="remarks" id="agreement_remarks" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('remarks')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-primary-button>
                    {{ $editingId ? 'Save Changes' : 'Add Agreement' }}
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-company-agreement-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this agreement?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                This cannot be undone.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="delete">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
