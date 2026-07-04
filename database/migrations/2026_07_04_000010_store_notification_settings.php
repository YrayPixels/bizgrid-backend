<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('notify_merchant_new_order')->default(true)->after('tiktok_auto_reply_enabled');
            $table->boolean('notify_customer_order_confirmation')->default(true)->after('notify_merchant_new_order');
            $table->boolean('notify_customer_payment_confirmation')->default(true)->after('notify_customer_order_confirmation');
            $table->boolean('notify_merchant_low_stock')->default(true)->after('notify_customer_payment_confirmation');
            $table->string('notification_email')->nullable()->after('notify_merchant_low_stock');
            $table->text('customer_order_note')->nullable()->after('notification_email');
            $table->string('sms_sender_name', 11)->nullable()->after('customer_order_note');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'notify_merchant_new_order',
                'notify_customer_order_confirmation',
                'notify_customer_payment_confirmation',
                'notify_merchant_low_stock',
                'notification_email',
                'customer_order_note',
                'sms_sender_name',
            ]);
        });
    }
};
