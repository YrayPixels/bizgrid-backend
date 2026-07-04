<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@storehause.local'],
            [
                'name' => 'Bizgrid Admin',
                'email_verified_at' => now(),
                'password' => Hash::make('Bizgrid123!'),
                'is_admin' => true,
                'admin_role' => 'super_admin',
            ],
        );
    }
}
