<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('STOREHAUSE_ADMIN_EMAIL', 'admin@storehause.local');
        $password = (string) env('STOREHAUSE_ADMIN_PASSWORD', '');

        if ($password === '' || $password === 'Bizgrid123!') {
            throw new RuntimeException(
                'Set STOREHAUSE_ADMIN_PASSWORD to a strong unique value before seeding the platform admin.'
            );
        }

        $admin = User::query()->firstOrNew(['email' => $email]);
        $admin->name = 'Bizgrid Admin';
        $admin->email_verified_at = now();
        $admin->password = Hash::make($password);
        $admin->is_admin = true;
        $admin->admin_role = 'super_admin';
        $admin->admin_permissions = AdminPermissions::all();
        $admin->save();
    }
}
