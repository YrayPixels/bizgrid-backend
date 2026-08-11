<?php

use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'admin_permissions')) {
                $table->json('admin_permissions')->nullable()->after('admin_role');
            }
        });

        $admins = DB::table('users')->where('is_admin', true)->get(['id', 'admin_role', 'admin_permissions']);

        foreach ($admins as $admin) {
            if (filled($admin->admin_permissions)) {
                continue;
            }

            $role = $admin->admin_role ?: 'support';
            $permissions = AdminPermissions::defaultsForRole($role);

            DB::table('users')->where('id', $admin->id)->update([
                'admin_permissions' => json_encode($permissions),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'admin_permissions')) {
                $table->dropColumn('admin_permissions');
            }
        });
    }
};
