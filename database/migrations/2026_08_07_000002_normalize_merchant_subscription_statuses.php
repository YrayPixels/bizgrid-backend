<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admin panel used to write `trial` and `past_due`, which no other part of the
     * system produced or understood — DodoPaymentsService writes `trialing`/`on_hold`.
     * Any merchant set through the old admin dropdown is stuck on a status that billing
     * logic ignores, so fold those onto the canonical spellings.
     */
    private const RENAMES = [
        'trial' => 'trialing',
        'past_due' => 'on_hold',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('merchants', 'subscription_status')) {
            return;
        }

        foreach (self::RENAMES as $legacy => $canonical) {
            DB::table('merchants')
                ->where('subscription_status', $legacy)
                ->update(['subscription_status' => $canonical]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('merchants', 'subscription_status')) {
            return;
        }

        // `on_hold` is also written legitimately by Dodo webhooks, so reversing would
        // mislabel merchants that were never touched by the old admin dropdown.
        // Renaming forward is safe; renaming back is not, so this is intentionally a no-op.
    }
};
