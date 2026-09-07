<?php

use App\Models\PersonalContact;
use App\Models\User;
use App\UserRole;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $role = 'staff';

    public bool $is_active = true;

    public string $formError = '';

    public ?int $confirmingDeleteId = null;

    public string $deleteBlockedMessage = '';

    /**
     * personal_contacts.user_id cascades on delete (Personal Ledger is
     * scoped per Super Admin) — flagged in the confirm dialog so deleting
     * an account that owns ledger data is a deliberate choice, not a
     * surprise.
     */
    public bool $confirmingDeleteHasPersonalLedger = false;

    /**
     * Captured once in mount() — a paginator built or re-resolved mid-session
     * would otherwise take its path from request()->url(), which resolves to
     * Livewire's own update endpoint during an AJAX re-render, not this
     * page's real URL.
     */
    public string $paginationPath = '';

    public function mount(): void
    {
        $this->paginationPath = request()->url();
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->email = '';
        $this->password = '';
        $this->role = UserRole::Staff->value;
        $this->is_active = true;
        $this->formError = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'user-form');
    }

    public function startEdit(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->role = $user->role->value;
        $this->is_active = $user->is_active;
        $this->formError = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'user-form');
    }

    public function save(): void
    {
        Gate::authorize('users.manage');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'password' => [Rule::requiredIf(! $this->editingId), 'nullable', 'string', 'min:8'],
            'role' => ['required', Rule::in(array_column(UserRole::cases(), 'value'))],
            'is_active' => ['boolean'],
        ]);

        $editing = $this->editingId ? User::findOrFail($this->editingId) : null;

        // A Super Admin can never demote or deactivate themselves, or the
        // last remaining active Super Admin — otherwise the app could end
        // up with nobody able to manage it at all.
        if ($editing) {
            $losingSuperAdmin = $editing->role === UserRole::SuperAdmin
                && ($validated['role'] !== UserRole::SuperAdmin->value || ! $validated['is_active']);

            if ($losingSuperAdmin && $editing->id === auth()->id()) {
                $this->formError = "You can't remove your own Super Admin access.";

                return;
            }

            if ($losingSuperAdmin) {
                $remainingSuperAdmins = User::where('role', UserRole::SuperAdmin)
                    ->where('is_active', true)
                    ->whereKeyNot($editing->id)
                    ->exists();

                if (! $remainingSuperAdmins) {
                    $this->formError = 'This is the last active Super Admin — promote someone else first.';

                    return;
                }
            }
        }

        $attributes = [
            'name' => trim($validated['name']),
            'email' => trim($validated['email']),
            'role' => $validated['role'],
            'is_active' => $validated['is_active'],
        ];

        if (! empty($validated['password'])) {
            $attributes['password'] = Hash::make($validated['password']);
        }

        if ($editing) {
            $editing->update($attributes);
        } else {
            User::create([...$attributes, 'password' => Hash::make($validated['password']), 'email_verified_at' => now()]);
        }

        $this->dispatch('close-modal', 'user-form');
        session()->flash('status', $editing ? 'User updated.' : 'User added.');
    }

    public function confirmDelete(int $userId): void
    {
        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            $this->deleteBlockedMessage = "You can't delete your own account.";
            $this->dispatch('open-modal', 'user-delete-blocked');

            return;
        }

        if ($this->isLastActiveSuperAdmin($user)) {
            $this->deleteBlockedMessage = 'This is the last active Super Admin — promote someone else first.';
            $this->dispatch('open-modal', 'user-delete-blocked');

            return;
        }

        $this->confirmingDeleteId = $userId;
        $this->confirmingDeleteHasPersonalLedger = PersonalContact::where('user_id', $userId)->exists();
        $this->dispatch('open-modal', 'confirm-user-deletion');
    }

    public function delete(): void
    {
        Gate::authorize('users.manage');

        if ($this->confirmingDeleteId) {
            $user = User::find($this->confirmingDeleteId);

            // A disabled control isn't a security boundary — re-check
            // authoritatively at delete time too, same as confirmDelete().
            if ($user && $user->id !== auth()->id() && ! $this->isLastActiveSuperAdmin($user)) {
                $user->delete();
                session()->flash('status', 'User deleted.');
            }
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-user-deletion');
    }

    private function isLastActiveSuperAdmin(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin
            && $user->is_active
            && ! User::where('role', UserRole::SuperAdmin)
                ->where('is_active', true)
                ->whereKeyNot($user->id)
                ->exists();
    }

    public function with(): array
    {
        return [
            'users' => User::orderBy('name')->simplePaginate(10)->setPath($this->paginationPath),
            'roles' => UserRole::cases(),
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Users</h3>
        <x-primary-button type="button" wire:click="startCreate">
            + Add User
        </x-primary-button>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-800">
        <table class="w-full min-w-[520px] text-sm">
            <thead>
                <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-400">
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Email</th>
                    <th class="px-4 py-2">Role</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr class="border-b border-slate-100 last:border-0 dark:border-slate-700/50">
                        <td class="px-4 py-2 text-slate-700 dark:text-slate-300">
                            {{ $user->name }}
                            @if ($user->id === auth()->id())
                                <span class="text-xs text-slate-400 dark:text-slate-500">(you)</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-slate-600 dark:text-slate-400">{{ $user->email }}</td>
                        <td class="px-4 py-2">
                            <x-badge :color="$user->role === \App\UserRole::SuperAdmin ? 'brand' : 'slate'">{{ $user->role->label() }}</x-badge>
                        </td>
                        <td class="px-4 py-2">
                            <x-badge :color="$user->is_active ? 'green' : 'red'">{{ $user->is_active ? 'Active' : 'Inactive' }}</x-badge>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button type="button" wire:click="startEdit({{ $user->id }})" class="text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                                Edit
                            </button>
                            <button type="button" wire:click="confirmDelete({{ $user->id }})" class="ml-3 text-xs font-medium text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                Delete
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{ $users->links('pagination::simple-tailwind') }}

    <x-modal name="user-form" focusable>
        <form wire:submit="save" class="space-y-6 p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">
                {{ $editingId ? 'Edit User' : 'Add User' }}
            </h2>

            @if ($formError)
                <p class="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-900/20 dark:text-red-300">
                    {{ $formError }}
                </p>
            @endif

            <div>
                <x-input-label for="user_name" value="Name" />
                <x-text-input wire:model="name" id="user_name" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="user_email" value="Email" />
                <x-text-input wire:model="email" id="user_email" type="email" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="user_password" :value="$editingId ? 'New Password' : 'Password'" />
                <x-text-input wire:model="password" id="user_password" type="password" class="mt-1 block w-full" :placeholder="$editingId ? 'Leave blank to keep current password' : ''" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="user_role" value="Role" />
                <x-select-input wire:model="role" id="user_role" class="mt-1 block w-full">
                    @foreach ($roles as $roleOption)
                        <option value="{{ $roleOption->value }}">{{ $roleOption->label() }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('role')" class="mt-2" />
            </div>

            <div class="flex items-center gap-2">
                <input type="checkbox" wire:model="is_active" id="user_is_active" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                <x-input-label for="user_is_active" value="Active (can log in)" class="!mb-0" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    Cancel
                </x-secondary-button>
                <x-primary-button>
                    {{ $editingId ? 'Save Changes' : 'Add User' }}
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="confirm-user-deletion" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Delete this user?</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                This cannot be undone. Their name stays on any purchases, sales, payments, invoices or
                expenses they created, but is no longer linked to an account.
            </p>
            @if ($confirmingDeleteHasPersonalLedger)
                <p class="mt-2 text-sm font-medium text-red-600 dark:text-red-400">
                    This user has Personal Ledger contacts, sales and payments — deleting the account
                    permanently erases that ledger data too.
                </p>
            @endif
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="button" wire:click="delete">Delete</x-danger-button>
            </div>
        </div>
    </x-modal>

    <x-modal name="user-delete-blocked" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-slate-900 dark:text-slate-100">Can't delete this user</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $deleteBlockedMessage }}</p>
            <div class="mt-6 flex justify-end">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">Close</x-secondary-button>
            </div>
        </div>
    </x-modal>
</div>
