<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_webhook_events', 'event_id')) {
                // Dodo's `webhook-id` header. Unique so a redelivery of the same event
                // collides on insert instead of granting allowances a second time.
                $table->string('event_id', 120)->nullable()->unique()->after('id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table) {
            if (Schema::hasColumn('billing_webhook_events', 'event_id')) {
                $table->dropUnique(['event_id']);
                $table->dropColumn('event_id');
            }
        });
    }
};
