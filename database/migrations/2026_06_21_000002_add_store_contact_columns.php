<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'contact_email')) {
                $table->string('contact_email', 255)->nullable()->after('logo_url');
            }

            if (! Schema::hasColumn('stores', 'contact_phone')) {
                $table->string('contact_phone', 40)->nullable()->after('contact_email');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            foreach (['contact_phone', 'contact_email'] as $column) {
                if (Schema::hasColumn('stores', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
