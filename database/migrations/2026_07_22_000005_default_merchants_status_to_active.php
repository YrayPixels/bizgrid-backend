<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Merchants that completed onboarding should show as active in admin.
        DB::table('merchants')
            ->where('status', 'pending')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('stores')
                    ->whereColumn('stores.merchant_id', 'merchants.id');
            })
            ->whereNull('activated_at')
            ->update([
                'status' => 'active',
                'activated_at' => $now,
                'updated_at' => $now,
            ]);

        DB::table('merchants')
            ->where('status', 'pending')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('stores')
                    ->whereColumn('stores.merchant_id', 'merchants.id');
            })
            ->whereNotNull('activated_at')
            ->update([
                'status' => 'active',
                'updated_at' => $now,
            ]);

        // Fill activated_at for already-active merchants missing it.
        DB::table('merchants')
            ->where('status', 'active')
            ->whereNull('activated_at')
            ->orderBy('id')
            ->each(function (object $merchant) use ($now) {
                DB::table('merchants')
                    ->where('id', $merchant->id)
                    ->update([
                        'activated_at' => $merchant->created_at ?? $now,
                        'updated_at' => $now,
                    ]);
            });

        Schema::table('merchants', function (Blueprint $table) {
            $table->string('status', 30)->default('active')->change();
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('status', 30)->default('pending')->change();
        });
    }
};
