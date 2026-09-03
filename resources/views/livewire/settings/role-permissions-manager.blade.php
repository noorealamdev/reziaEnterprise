<?php

use App\Models\RolePermission;
use App\Permission;
use App\UserRole;
use Illuminate\Support\Facades\Gate;
use Livewire\Volt\Component;

new class extends Component
{
    /** @var array<string, bool> */
    public array $accountantGrants = [];

    /** @var array<string, bool> */
    public array $staffGrants = [];

    public function mount(): void
    {
        $this->loadGrants();
    }

    /**
     * Grants are keyed by the enum case name (e.g. "JobEntriesCreate"), not
     * its dotted value ("job_entries.create") — Livewire's dot-notation
     * property binding (`wire:model="staffGrants.job_entries.create"`)
     * would otherwise parse each dot as a nesting level and write into a
     * nested array instead of this flat one.
     */
    private function loadGrants(): void
    {
        $accountantKeys = RolePermission::where('role', UserRole::Accountant->value)->pluck('permission')->all();
        $staffKeys = RolePermission::where('role', UserRole::Staff->value)->pluck('permission')->all();

        foreach (Permission::cases() as $permission) {
            $this->accountantGrants[$permission->name] = in_array($permission->value, $accountantKeys, true);
            $this->staffGrants[$permission->name] = in_array($permission->value, $staffKeys, true);
        }
    }

    public function saveAccountant(): void
    {
        $this->saveRole(UserRole::Accountant, $this->accountantGrants);
    }

    public function saveStaff(): void
    {
        $this->saveRole(UserRole::Staff, $this->staffGrants);
    }

    /**
     * @param  array<string, bool>  $grants
     */
    private function saveRole(UserRole $role, array $grants): void
    {
        Gate::authorize('users.manage');

        $granted = collect(Permission::cases())
            ->filter(fn (Permission $permission) => $grants[$permission->name] ?? false)
            ->map(fn (Permission $permission) => $permission->value)
            ->all();

        RolePermission::where('role', $role->value)->whereNotIn('permission', $granted)->delete();

        foreach ($granted as $permissionKey) {
            RolePermission::updateOrCreate(['role' => $role->value, 'permission' => $permissionKey]);
        }

        session()->flash('status', "{$role->label()} permissions updated.");
    }

    public function with(): array
    {
        return [
            'groupedPermissions' => Permission::grouped(),
        ];
    }
}; ?>

<div class="space-y-6">
    <p class="text-xs text-slate-500 dark:text-slate-400">
        Super Admin always has full access and isn't shown here. Accountant and Staff only ever have exactly what's checked below.
    </p>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <form wire:submit="saveAccountant" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Accountant</h3>

            <div class="mt-4 space-y-4">
                @foreach ($groupedPermissions as $group => $permissions)
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ $group }}</p>
                        <div class="mt-1 space-y-1">
                            @foreach ($permissions as $permission)
                                <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                    <input type="checkbox" wire:model="accountantGrants.{{ $permission->name }}" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    {{ $permission->label() }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <x-primary-button type="submit" class="mt-5">
                Save Accountant Permissions
            </x-primary-button>
        </form>

        <form wire:submit="saveStaff" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-800">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Staff</h3>

            <div class="mt-4 space-y-4">
                @foreach ($groupedPermissions as $group => $permissions)
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ $group }}</p>
                        <div class="mt-1 space-y-1">
                            @foreach ($permissions as $permission)
                                <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                    <input type="checkbox" wire:model="staffGrants.{{ $permission->name }}" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    {{ $permission->label() }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <x-primary-button type="submit" class="mt-5">
                Save Staff Permissions
            </x-primary-button>
        </form>
    </div>
</div>
