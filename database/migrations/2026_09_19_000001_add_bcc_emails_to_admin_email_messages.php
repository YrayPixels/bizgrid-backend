<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_email_messages')) {
            return;
        }

        Schema::table('admin_email_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('admin_email_messages', 'bcc_emails')) {
                $table->json('bcc_emails')->nullable()->after('cc_emails');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('admin_email_messages')) {
            return;
        }

        Schema::table('admin_email_messages', function (Blueprint $table) {
            if (Schema::hasColumn('admin_email_messages', 'bcc_emails')) {
                $table->dropColumn('bcc_emails');
            }
        });
    }
};
