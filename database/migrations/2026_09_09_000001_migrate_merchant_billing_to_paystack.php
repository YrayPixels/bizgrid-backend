<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('paystack_customer_code')->nullable()->after('subscription_status');
            $table->string('paystack_subscription_code')->nullable()->after('paystack_customer_code');
            $table->string('paystack_email_token')->nullable()->after('paystack_subscription_code');
        });

        if (Schema::hasColumn('merchants', 'dodo_customer_id')) {
            DB::table('merchants')->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('merchants')->where('id', $row->id)->update([
                        'paystack_customer_code' => $row->dodo_customer_id,
                        'paystack_subscription_code' => $row->dodo_subscription_id,
                    ]);
                }
            });

            Schema::table('merchants', function (Blueprint $table) {
                $table->dropColumn(['dodo_customer_id', 'dodo_subscription_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('dodo_customer_id')->nullable()->after('subscription_status');
            $table->string('dodo_subscription_id')->nullable()->after('dodo_customer_id');
        });

        DB::table('merchants')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('merchants')->where('id', $row->id)->update([
                    'dodo_customer_id' => $row->paystack_customer_code,
                    'dodo_subscription_id' => $row->paystack_subscription_code,
                ]);
            }
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn([
                'paystack_customer_code',
                'paystack_subscription_code',
                'paystack_email_token',
            ]);
        });
    }
};
