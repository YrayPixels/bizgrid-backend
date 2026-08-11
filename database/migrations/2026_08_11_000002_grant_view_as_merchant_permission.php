<?php

use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $admins = DB::table('users')
            ->where('is_admin', true)
            ->get(['id', 'admin_role', 'admin_permissions']);

        foreach ($admins as $admin) {
            $role = $admin->admin_role ?: 'support';
            $stored = null;

            if (filled($admin->admin_permissions)) {
                $decoded = json_decode((string) $admin->admin_permissions, true);
                $stored = is_array($decoded) ? $decoded : null;
            }

            $effective = AdminPermissions::effective($stored, $role);

            // Preserve existing custom grants for support/super_admin who previously
            // could impersonate via role, by ensuring view_as_merchant is present.
            if (in_array($role, ['support', 'super_admin'], true)
                && ! in_array(AdminPermissions::VIEW_AS_MERCHANT, $effective, true)
            ) {
                $effective[] = AdminPermissions::VIEW_AS_MERCHANT;
            }

            $normalized = AdminPermissions::normalize($effective, $role);

            DB::table('users')->where('id', $admin->id)->update([
                'admin_permissions' => json_encode(array_values($normalized)),
            ]);
        }
    }

    public function down(): void
    {
        $admins = DB::table('users')
            ->where('is_admin', true)
            ->get(['id', 'admin_role', 'admin_permissions']);

        foreach ($admins as $admin) {
            if (! filled($admin->admin_permissions)) {
                continue;
            }

            $decoded = json_decode((string) $admin->admin_permissions, true);
            if (! is_array($decoded)) {
                continue;
            }

            $filtered = array_values(array_filter(
                $decoded,
                fn ($key) => $key !== AdminPermissions::VIEW_AS_MERCHANT
            ));

            DB::table('users')->where('id', $admin->id)->update([
                'admin_permissions' => json_encode($filtered),
            ]);
        }
    }
};
