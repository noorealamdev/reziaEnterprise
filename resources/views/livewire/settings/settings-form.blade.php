<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    #[Url(as: 'tab', history: true)]
    public string $activeTab = 'logo';

    public $logo = null;

    public bool $confirmingLogoRemoval = false;

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function uploadLogo(): void
    {
        Gate::authorize('settings.manage');

        $this->validate([
            'logo' => ['required', 'image', 'max:2048'],
        ]);

        $setting = Setting::current();
        $oldPath = $setting->logo_path;

        $path = $this->logo->store('logo', 'public');
        $setting->update(['logo_path' => $path]);

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        $this->logo = null;
        session()->flash('status', 'Logo updated.');
    }

    public function confirmRemoveLogo(): void
    {
        $this->confirmingLogoRemoval = true;
        $this->dispatch('open-modal', 'confirm-logo-removal');
    }

    public function removeLogo(): void
    {
        Gate::authorize('settings.manage');

        $setting = Setting::current();

        if ($setting->logo_path) {
            Storage::disk('public')->delete($setting->logo_path);
            $setting->update(['logo_path' => null]);
        }

        $this->confirmingLogoRemoval = false;
        $this->dispatch('close-modal', 'confirm-logo-removal');
        session()->flash('status', 'Logo removed — back to the default mark.');
    }

    public function with(): array
    {
        $setting = Setting::current();
        $user = auth()->user();

        $canManageSettings = $user->hasPermission(\App\Permission::SettingsManage) || Gate::allows('users.manage');
        $canManageUsers = Gate::allows('users.manage');

        // Land on a tab this user can actually see — a non-Super-Admin
        // hitting the Users/Roles tab via a stale URL falls back to Logo
        // rather than rendering a blank shell.
        if ($this->activeTab === 'logo' && ! $canManageSettings) {
            $this->activeTab = $canManageUsers ? 'users' : 'logo';
        }
        if (in_array($this->activeTab, ['users', 'roles'], true) && ! $canManageUsers) {
            $this->activeTab = 'logo';
        }

        return [
            'logoUrl' => $setting->logo_path ? Storage::disk('public')->url($setting->logo_path) : null,
            'canManageSettings' => $canManageSettings,
            'canManageUsers' => $canManageUsers,
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-wrap gap-2 border-b border-slate-200 dark:border-slate-700">
        @if ($canManageSettings)
            <button
                type="button"
                wire:click="switchTab('logo')"
                class="border-b-2 px-1 pb-2 text-sm font-medium {{ $activeTab === 'logo' ? 'border-brand-600 text-brand-600 dark:border-brand-400 dark:text-brand-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
            >
                Company Logo
            </button>
        @endif
        @if ($canManageUsers)
            <button
                type="button"
                wire:click="switchTab('users')"
                class="border-b-2 px-1 pb-2 text-sm font-medium {{ $activeTab === 'users' ? 'border-brand-600 text-brand-600 dark:border-brand-400 dark:text-brand-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
            >
                Users
            </button>
            <button
                type="button"
                wire:click="switchTab('roles')"
                class="border-b-2 px-1 pb-2 text-sm font-medium {{ $activeTab === 'roles' ? 'border-brand-600 text-brand-600 dark:border-brand-400 dark:text-brand-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}"
            >
                Roles &amp; Permissions
            </button>
        @endif
    </div>

    @if ($activeTab === 'logo' && $canManageSettings)
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Company Logo</h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Shown in the sidebar, the login page, and on every printed invoice and bill statement. Without one, a default mark is used instead.
            </p>

            <div class="mt-4 flex flex-wrap items-center gap-4">
                <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                    @if ($logoUrl)
                        <img src="{{ $logoUrl }}" alt="Company logo" class="h-full w-full object-contain">
                    @else
                        <x-application-logo class="h-full w-full" />
                    @endif
                </div>

                @if ($logoUrl)
                    <div class="flex flex-col gap-1">
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Custom logo active</span>
                        <button type="button" wire:click="confirmRemoveLogo" class="text-left text-xs font-medium text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                            Remove and use the default mark
                        </button>
                    </div>
                @else
                    <span class="text-sm text-slate-500 dark:text-slate-400">Using the default mark</span>
                @endif
            </div>

            <form wire:submit="uploadLogo" class="mt-4 flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1">
                    <x-input-label for="logo" :value="$logoUrl ? 'Replace logo' : 'Upload logo'" />
                    <input type="file" wire:model="logo" id="logo" accept="image/*" class="mt-1 block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 dark:text-slate-400 dark:file:bg-slate-700 dark:file:text-slate-200">
                    <div wire:loading wire:target="logo" class="mt-1 text-xs text-slate-400 dark:text-slate-500">Uploading…</div>
                    @if ($logo)
                        @if ($logo->isPreviewable())
                            <img src="{{ $logo->temporaryUrl() }}" alt="Logo preview" class="mt-2 h-16 w-16 rounded-lg border border-slate-200 object-contain dark:border-slate-700">
                        @else
                            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Selected: {{ $logo->getClientOriginalName() }}</p>
                        @endif
                    @endif
                    <x-input-error :messages="$errors->get('logo')" class="mt-2" />
                </div>
                <x-primary-button type="submit">
                    {{ $logoUrl ? 'Replace' : 'Upload' }}
                </x-primary-button>
            </form>
        </div>

        <x-modal name="confirm-logo-removal" focusable>
            <div class="p-6">
                <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Remove the company logo?</h2>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    The sidebar, login page, and invoices will fall back to the default mark. You can upload another logo any time.
                </p>
                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                    <x-danger-button type="button" wire:click="removeLogo">Remove</x-danger-button>
                </div>
            </div>
        </x-modal>
    @elseif ($activeTab === 'users' && $canManageUsers)
        <livewire:settings.user-manager />
    @elseif ($activeTab === 'roles' && $canManageUsers)
        <livewire:settings.role-permissions-manager />
    @endif
</div>
