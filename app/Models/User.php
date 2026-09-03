<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Permission;
use App\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Permission keys granted to this user's role, memoized per instance so
     * a single request never re-queries per hasPermission() call.
     *
     * @var array<string, bool>|null
     */
    private ?array $grantedPermissionKeys = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Super Admin always passes — every other role only has whatever was
     * explicitly granted via the Roles & Permissions screen (RolePermission
     * rows), defaulting to nothing.
     */
    public function hasPermission(Permission $permission): bool
    {
        if ($this->role === UserRole::SuperAdmin) {
            return true;
        }

        if ($this->grantedPermissionKeys === null) {
            $this->grantedPermissionKeys = RolePermission::where('role', $this->role->value)
                ->pluck('permission')
                ->flip()
                ->map(fn () => true)
                ->all();
        }

        return $this->grantedPermissionKeys[$permission->value] ?? false;
    }
}
