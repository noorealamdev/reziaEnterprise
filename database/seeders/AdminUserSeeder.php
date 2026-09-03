<?php

namespace Database\Seeders;

use App\Models\User;
use App\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $email = 'noorealamdev@gmail.com';

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'password' => Hash::make('12345678'),
                'email_verified_at' => now(),
                'role' => UserRole::SuperAdmin,
                'is_active' => true,
            ]
        );

        $this->command?->info("Admin user ready: {$email} / 12345678");
    }
}
