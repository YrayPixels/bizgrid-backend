<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'admin_role')) {
                $table->string('admin_role', 30)->nullable()->after('is_admin');
            }
        });

        Schema::table('merchants', function (Blueprint $table) {
            if (! Schema::hasColumn('merchants', 'tags')) {
                $table->json('tags')->nullable()->after('suspension_reason');
            }
        });

        if (! Schema::hasTable('merchant_notes')) {
            Schema::create('merchant_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
                $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('body');
                $table->timestamps();
                $table->index(['merchant_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('billing_webhook_events')) {
            Schema::create('billing_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
                $table->string('event_type', 80)->index();
                $table->string('status', 30)->default('processed');
                $table->json('payload')->nullable();
                $table->timestamps();
                $table->index(['merchant_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('platform_notifications')) {
            Schema::create('platform_notifications', function (Blueprint $table) {
                $table->id();
                $table->string('type', 60)->index();
                $table->string('title');
                $table->text('body')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_notifications');
        Schema::dropIfExists('billing_webhook_events');
        Schema::dropIfExists('merchant_notes');

        Schema::table('merchants', function (Blueprint $table) {
            if (Schema::hasColumn('merchants', 'tags')) {
                $table->dropColumn('tags');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'admin_role')) {
                $table->dropColumn('admin_role');
            }
        });
    }
};
