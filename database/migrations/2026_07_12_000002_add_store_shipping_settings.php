<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'allow_local_delivery')) {
                $table->boolean('allow_local_delivery')->default(true)->after('sms_sender_name');
            }
            if (! Schema::hasColumn('stores', 'allow_pickup')) {
                $table->boolean('allow_pickup')->default(false)->after('allow_local_delivery');
            }
            if (! Schema::hasColumn('stores', 'default_delivery_fee')) {
                $table->decimal('default_delivery_fee', 14, 2)->nullable()->after('allow_pickup');
            }
            if (! Schema::hasColumn('stores', 'fulfilment_promise')) {
                $table->string('fulfilment_promise', 255)->nullable()->after('default_delivery_fee');
            }
            if (! Schema::hasColumn('stores', 'shipping_policy')) {
                $table->text('shipping_policy')->nullable()->after('fulfilment_promise');
            }
            if (! Schema::hasColumn('stores', 'return_policy')) {
                $table->text('return_policy')->nullable()->after('shipping_policy');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            foreach ([
                'allow_local_delivery',
                'allow_pickup',
                'default_delivery_fee',
                'fulfilment_promise',
                'shipping_policy',
                'return_policy',
            ] as $column) {
                if (Schema::hasColumn('stores', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
