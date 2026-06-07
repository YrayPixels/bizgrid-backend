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
                'name' => 'StoreHause Admin',
                'email_verified_at' => now(),
                'password' => Hash::make('StoreHause123!'),
            ],
        );
    }
}
