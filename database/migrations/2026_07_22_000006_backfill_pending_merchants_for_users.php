<?php

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        User::query()
            ->where('is_admin', false)
            ->whereDoesntHave('merchant')
            ->orderBy('id')
            ->each(function (User $user) {
                Merchant::ensurePendingForUser($user);
            });
    }

    public function down(): void
    {
        // Keep merchant rows created for historical signups.
    }
};
