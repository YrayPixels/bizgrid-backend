<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove legacy HeySolana and SchoolOS tables.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        $tables = [
            // SchoolOS — child tables first
            'invoice_payments',
            'invoice_items',
            'fee_assignments',
            'student_enrollments',
            'attendance_records',
            'class_subject',
            'timetable_periods',
            'school_messages',
            'school_events',
            'invoices',
            'fee_templates',
            'fee_categories',
            'academic_terms',
            'subjects',
            'school_classes',
            'students',
            'employees',
            'school_employee_roles',
            'school_employee_departments',
            'academic_sessions',
            'school_user',
            'schools',

            // HeySolana legacy
            'jumia_order_history',
            'jumia_order_items',
            'jumia_orders',
            'jumia_delivery_addresses',
            'crossmint_orders',
            'app_bug_reports',
            'app_transactions',
            'agent_wallets',
            'passkey_credentials',
            'mpc_wallet_shares',
            'mpc_device_link_sessions',
            'push_notification_tokens',
            'voice_profiles',
            'personal_contacts',
            'selectedchain',
            'transactions',
            'button_clicks',
            'tool_calls',
            'app_open_count',
            'page_open_count',
            'token_usage',
            'user_activity_events',
            'twitterbot',
            'twitterbotusers',
            'waitlist',
            'cookie_manager',
            'addressbook',
            'settings',
            'paj_cash_beneficiaries',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Intentionally empty — legacy tables are not recreated.
    }
};
