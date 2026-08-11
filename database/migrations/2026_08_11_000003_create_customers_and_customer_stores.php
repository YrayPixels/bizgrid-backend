<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->string('google_id')->nullable()->unique();
                $table->string('email')->unique();
                $table->string('name', 160);
                $table->string('avatar_url', 2048)->nullable();
                $table->timestamp('email_verified_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('customer_stores')) {
            Schema::create('customer_stores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->unique(['customer_id', 'store_id']);
                $table->index(['store_id', 'last_seen_at']);
            });
        }

        if (Schema::hasTable('try_on_sessions') && ! Schema::hasColumn('try_on_sessions', 'customer_id')) {
            Schema::table('try_on_sessions', function (Blueprint $table) {
                $table->foreignId('customer_id')
                    ->nullable()
                    ->after('store_id')
                    ->constrained('customers')
                    ->nullOnDelete();
                $table->index(['customer_id', 'store_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('try_on_sessions') && Schema::hasColumn('try_on_sessions', 'customer_id')) {
            Schema::table('try_on_sessions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('customer_id');
            });
        }

        Schema::dropIfExists('customer_stores');
        Schema::dropIfExists('customers');
    }
};
