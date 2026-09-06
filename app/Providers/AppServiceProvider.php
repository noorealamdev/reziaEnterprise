<?php

namespace App\Providers;

use App\Models\User;
use App\Permission;
use App\UserRole;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user) => $user->hasPermission($permission));
        }

        // User management is never a grantable permission — only a hard
        // Super Admin check, so no misconfigured grant can ever let someone
        // promote themselves or another user to Super Admin.
        Gate::define('users.manage', fn (User $user) => $user->role === UserRole::SuperAdmin);

        // The Personal Ledger is the client's own private side-business
        // tracker (cash sales to personal contacts, separate from Rezia
        // Enterprise's own books) — same hard, non-grantable Super Admin
        // check as users.manage, deliberately never added to the Permission
        // enum so no Super Admin can ever delegate it to Staff/Accountant.
        Gate::define('personal-ledger.manage', fn (User $user) => $user->role === UserRole::SuperAdmin);
    }
}
