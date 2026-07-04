<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            if (! Schema::hasColumn('merchants', 'sms_included_remaining')) {
                $table->unsignedInteger('sms_included_remaining')->default(0)->after('subscription_renews_at');
            }
            if (! Schema::hasColumn('merchants', 'sms_purchased_balance')) {
                $table->unsignedInteger('sms_purchased_balance')->default(0)->after('sms_included_remaining');
            }
            if (! Schema::hasColumn('merchants', 'whatsapp_included_remaining')) {
                $table->unsignedInteger('whatsapp_included_remaining')->default(0)->after('sms_purchased_balance');
            }
            if (! Schema::hasColumn('merchants', 'whatsapp_purchased_balance')) {
                $table->unsignedInteger('whatsapp_purchased_balance')->default(0)->after('whatsapp_included_remaining');
            }
            if (! Schema::hasColumn('merchants', 'ai_purchased_credits')) {
                $table->unsignedInteger('ai_purchased_credits')->default(0)->after('whatsapp_purchased_balance');
            }
            if (! Schema::hasColumn('merchants', 'ai_credits_used_today')) {
                $table->unsignedSmallInteger('ai_credits_used_today')->default(0)->after('ai_purchased_credits');
            }
            if (! Schema::hasColumn('merchants', 'ai_credits_date')) {
                $table->date('ai_credits_date')->nullable()->after('ai_credits_used_today');
            }
            if (! Schema::hasColumn('merchants', 'monthly_processed_ngn')) {
                $table->decimal('monthly_processed_ngn', 14, 2)->default(0)->after('ai_credits_date');
            }
            if (! Schema::hasColumn('merchants', 'monthly_usage_period_start')) {
                $table->date('monthly_usage_period_start')->nullable()->after('monthly_processed_ngn');
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            foreach ([
                'monthly_usage_period_start',
                'monthly_processed_ngn',
                'ai_credits_date',
                'ai_credits_used_today',
                'ai_purchased_credits',
                'whatsapp_purchased_balance',
                'whatsapp_included_remaining',
                'sms_purchased_balance',
                'sms_included_remaining',
            ] as $column) {
                if (Schema::hasColumn('merchants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
