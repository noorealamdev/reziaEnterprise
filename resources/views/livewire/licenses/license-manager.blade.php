<?php

use App\Models\License;
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

    /** '', 'expiring', 'expired', or 'active' (not expiring soon, not expired). */
    #[Url(as: 'status', history: true)]
    public string $statusFilter = '';

    public ?int $editingId = null;

    public string $title = '';

    public ?string $license_number = null;

    public string $issue_date = '';

    public string $expiry_date = '';

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
            'status' => $this->statusFilter,
        ], fn ($value) => $value !== '');
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->title = '';
        $this->license_number = null;
        $this->issue_date = now()->toDateString();
        $this->expiry_date = '';
        $this->remarks = null;
        $this->existingDocumentPath = null;
        $this->documentFile = null;
        $this->removeDocument = false;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'license-form');
    }

    public function startEdit(int $licenseId): void
    {
        $license = License::findOrFail($licenseId);
        $this->editingId = $license->id;
        $this->title = $license->title;
        $this->license_number = $license->license_number;
        $this->issue_date = $license->issue_date?->format('Y-m-d') ?? '';
        $this->expiry_date = $license->expiry_date->format('Y-m-d');
        $this->remarks = $license->remarks;
        $this->existingDocumentPath = $license->document_path;
        $this->documentFile = null;
        $this->removeDocument = false;
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'license-form');
    }

    public function clearDocument(): void
    {
        $this->existingDocumentPath = null;
        $this->removeDocument = true;
    }

    public function save(): void
    {
        Gate::authorize($this->editingId ? 'licenses.modify' : 'licenses.create');

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'documentFile' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['title'] = trim($validated['title']);
        $validated['license_number'] = $validated['license_number'] ? trim($validated['license_number']) : null;
        $validated['issue_date'] = $validated['issue_date'] ?: null;
        $validated['remarks'] = $validated['remarks'] ? trim($validated['remarks']) : null;

        $existingLicense = $this->editingId ? License::find($this->editingId) : null;

        // Renewing (or otherwise changing) the deadline clears any prior
        // alert so the resend clock restarts against the new date — an old
        // "already alerted" timestamp must never suppress a fresh deadline.
        if ($existingLicense && $existingLicense->expiry_date->format('Y-m-d') !== $validated['expiry_date']) {
            $validated['last_alerted_at'] = null;
        }

        unset($validated['documentFile']);

        if ($this->documentFile) {
            if ($existingLicense?->document_path) {
                Storage::disk('public')->delete($existingLicense->document_path);
            }
            $validated['document_path'] = $this->documentFile->store('licenses', 'public');
        } elseif ($this->removeDocument) {
            if ($existingLicense?->document_path) {
                Storage::disk('public')->delete($existingLicense->document_path);
            }
            $validated['document_path'] = null;
        }

        if ($this->editingId) {
            License::whereKey($this->editingId)->update($validated);
        } else {
            $validated['created_by'] = auth()->id();
            License::create($validated);
        }

        $this->documentFile = null;
        $this->removeDocument = false;
        $this->dispatch('close-modal', 'license-form');
        session()->flash('status', $this->editingId ? 'License updated.' : 'License recorded.');
    }

    public function confirmDelete(int $licenseId): void
    {
        $this->confirmingDeleteId = $licenseId;
        $this->dispatch('open-modal', 'confirm-license-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('licenses.modify');

        if ($this->confirmingDeleteId) {
            $license = License::find($this->confirmingDeleteId);

            if ($license?->document_path) {
                Storage::disk('public')->delete($license->document_path);
            }

            $license?->delete();
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-license-deletion');
        session()->flash('status', 'License deleted.');
    }

    public function with(): array
    {
        $today = now()->toDateString();
        $alertDays = (int) config('reports.license_deadline_alert_days');
        $alertCutoff = now()->addDays($alertDays)->toDateString();

        $licensesQuery = License::query()
            ->when($this->statusFilter === 'expired', fn ($query) => $query->whereDate('expiry_date', '<', $today))
            ->when($this->statusFilter === 'expiring', fn ($query) => $query->whereDate('expiry_date', '>=', $today)->whereDate('expiry_date', '<=', $alertCutoff))
            ->when($this->statusFilter === 'active', fn ($query) => $query->whereDate('expiry_date', '>', $alertCutoff));

        return [
            'licenses' => $licensesQuery->clone()
                ->orderBy('expiry_date')
                ->orderByDesc('id')
                ->simplePaginate(20)
                ->setPath($this->paginationPath)
                ->appends($this->urlQueryState()),
            'expiringCount' => License::query()
                ->whereDate('expiry_date', '>=', $today)
                ->whereDate('expiry_date', '<=', $alertCutoff)
                ->count(),
            'expiredCount' => License::query()
                ->whereDate('expiry_date', '<', $today)
                ->count(),
            'alertDays' => $alertDays,
            'existingDocumentUrl' => $this->existingDocumentPath ? Storage::disk('public')->url($this->existingDocumentPath) : null,
            'existingDocumentIsPdf' => str_ends_with((string) $this->existingDocumentPath, '.pdf'),
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Licenses</h2>
        @can('licenses.create')
            <x-primary-button type="button" wire:click="startCreate">
                + Add License
            </x-primary-button>
        @endcan
    </div>

    <p class="text-xs text-slate-500 dark:text-slate-400">
        Every license Rezia Enterprise holds (Trade License, etc.), and when it's due for renewal — Super
        Admins get an email once a deadline is within {{ $alertDays }} days, and every {{ $alertDays }}
        days' worth of "Expiring Soon" licenses can also be seen right here.
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
        <x-select-input wire:model.live="statusFilter" class="w-full sm:w-48">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="expiring">Expiring Soon</option>
            <option value="expired">Expired</option>
        </x-select-input>
    </div>

    @forelse ($licenses as $license)
        @php
            $daysLeft = (int) round(now()->diffInDays($license->expiry_date, absolute: true));
        @endphp
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $license->title }}</h3>
                        @if ($license->is_expired)
                            <x-badge color="red">Expired {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }} ago</x-badge>
                        @elseif ($daysLeft <= $alertDays)
                            <x-badge color="amber">Expires in {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }}</x-badge>
                        @else
                            <x-badge color="green">Active</x-badge>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        @if ($license->license_number)
                            {{ $license->license_number }} ·
                        @endif
                        @if ($license->issue_date)
                            {{ $license->issue_date->format('d M Y') }} &ndash; {{ $license->expiry_date->format('d M Y') }}
                        @else
                            Expires {{ $license->expiry_date->format('d M Y') }}
                        @endif
                    </p>
                    @if ($license->document_url)
                        <a href="{{ $license->document_url }}" target="_blank" rel="noopener" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h1M6 4h12a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z" /></svg>
                            {{ $license->document_is_pdf ? 'View Document (PDF)' : 'View Document' }}
                        </a>
                    @endif
                    @if ($license->remarks)
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $license->remarks }}</p>
                    @endif
                </div>
            </div>

            @can('licenses.modify')
                <div class="mt-4 flex items-center gap-3">
                    <x-secondary-button type="button" wire:click="startEdit({{ $license->id }})">
                        Edit
                    </x-secondary-button>
                    <x-danger-button type="button" wire:click="confirmDelete({{ $license->id }})">
                        Delete
                    </x-danger-button>
                </div>
            @endcan
        </div>
    @empty
        <x-empty-state
            title="No licenses recorded yet"
            message="Add a license to start tracking its renewal deadline."
        />
    @endforelse

    {{ $licenses->links('pagination::simple-tailwind') }}

    <x-modal name="license-form" focusable>
        <form wire:submit="save" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingId ? 'Edit License' : 'Add License' }}
            </h2>

            <div>
                <x-input-label for="license_title" value="License Title" />
                <x-text-input wire:model="title" id="license_title" placeholder="e.g. Trade License 2026-2027" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('title')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="license_number" value="License Number (optional)" />
                <x-text-input wire:model="license_number" id="license_number" placeholder="e.g. TL-123456" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('license_number')" class="mt-2" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="license_issue_date" value="Issue Date (optional)" />
                    <x-text-input wire:model="issue_date" id="license_issue_date" type="date" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('issue_date')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="license_expiry_date" value="Renewal Deadline / Expiry Date" />
                    <x-text-input wire:model="expiry_date" id="license_expiry_date" type="date" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('expiry_date')" class="mt-2" />
                </div>
            </div>

            <div>
                <x-input-label for="license_document" value="License Document" />

                @if ($existingDocumentUrl)
                    <div class="mt-1 flex items-center gap-3">
                        @if ($existingDocumentIsPdf)
                            <a href="{{ $existingDocumentUrl }}" target="_blank" rel="noopener" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                                PDF
                            </a>
                        @else
                            <a href="{{ $existingDocumentUrl }}" target="_blank" rel="noopener">
                                <img src="{{ $existingDocumentUrl }}" alt="License document" class="h-12 w-12 shrink-0 rounded-lg border border-slate-200 object-cover dark:border-slate-700">
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

                <input type="file" wire:model="documentFile" id="license_document" accept="image/*,.pdf" class="mt-2 block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 dark:text-slate-400 dark:file:bg-slate-700 dark:file:text-slate-200">
                <div wire:loading wire:target="documentFile" class="mt-1 text-xs text-slate-400 dark:text-slate-500">Uploading…</div>
                @if ($documentFile && $documentFile->isPreviewable())
                    <img src="{{ $documentFile->temporaryUrl() }}" alt="License document preview" class="mt-2 max-h-24 rounded-lg border border-slate-200 dark:border-slate-700">
                @endif
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">JPG, PNG or PDF, up to 10MB.{{ $existingDocumentUrl ? ' Choosing a new file replaces the one above.' : '' }}</p>
                <x-input-error :messages="$errors->get('documentFile')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="license_remarks" value="Remarks" />
                <x-textarea-input wire:model="remarks" id="license_remarks" placeholder="Optional" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('remarks')" class="mt-2" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-primary-button>
                    {{ $editingId ? 'Save Changes' : 'Add License' }}
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-license-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this license?</h2>
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
