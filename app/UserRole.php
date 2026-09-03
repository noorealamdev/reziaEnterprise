<?php

namespace App;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Accountant = 'accountant';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Accountant => 'Accountant',
            self::Staff => 'Staff',
        };
    }
}
