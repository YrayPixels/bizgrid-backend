<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_locations')) {
            Schema::create('store_locations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->string('name', 120);
                $table->boolean('is_default')->default(false);
                $table->timestamps();

                $table->index(['store_id', 'is_default']);
            });
        }

        if (! Schema::hasTable('merchant_staff')) {
            Schema::create('merchant_staff', function (Blueprint $table) {
                $table->id();
                $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role', 32); // manager | cashier
                $table->string('status', 32)->default('active'); // invited | active | disabled
                $table->foreignId('default_location_id')->nullable()->constrained('store_locations')->nullOnDelete();
                $table->timestamps();

                $table->unique(['merchant_id', 'user_id']);
                $table->index(['merchant_id', 'status']);
            });
        }

        if (! Schema::hasTable('merchant_staff_invites')) {
            Schema::create('merchant_staff_invites', function (Blueprint $table) {
                $table->id();
                $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
                $table->string('email');
                $table->string('role', 32);
                $table->foreignId('location_id')->nullable()->constrained('store_locations')->nullOnDelete();
                $table->string('token', 64)->unique();
                $table->timestamp('expires_at');
                $table->timestamp('accepted_at')->nullable();
                $table->timestamps();

                $table->index(['merchant_id', 'email']);
            });
        }

        // Seed a default location for existing stores.
        if (Schema::hasTable('stores') && Schema::hasTable('store_locations')) {
            $stores = DB::table('stores')->select('id', 'name')->get();
            foreach ($stores as $store) {
                $exists = DB::table('store_locations')->where('store_id', $store->id)->exists();
                if ($exists) {
                    continue;
                }
                DB::table('store_locations')->insert([
                    'store_id' => $store->id,
                    'name' => 'Main',
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_staff_invites');
        Schema::dropIfExists('merchant_staff');
        Schema::dropIfExists('store_locations');
    }
};
