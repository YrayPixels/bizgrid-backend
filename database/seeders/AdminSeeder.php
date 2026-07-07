<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('STOREHAUSE_ADMIN_EMAIL', 'admin@storehause.local');
        $password = (string) env('STOREHAUSE_ADMIN_PASSWORD', 'Bizgrid123!');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Bizgrid Admin',
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'is_admin' => true,
                'admin_role' => 'super_admin',
            ],
        );
    }
}
