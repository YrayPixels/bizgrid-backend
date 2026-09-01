<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bizfest_partner_inquiries')) {
            return;
        }

        Schema::table('bizfest_partner_inquiries', function (Blueprint $table) {
            if (! Schema::hasColumn('bizfest_partner_inquiries', 'interests')) {
                $table->json('interests')->nullable()->after('tier_interest');
            }
            if (! Schema::hasColumn('bizfest_partner_inquiries', 'booth_package')) {
                $table->string('booth_package', 80)->nullable()->after('interests');
            }
            if (! Schema::hasColumn('bizfest_partner_inquiries', 'space_package')) {
                $table->string('space_package', 80)->nullable()->after('booth_package');
            }
            if (! Schema::hasColumn('bizfest_partner_inquiries', 'booth_quantity')) {
                $table->unsignedTinyInteger('booth_quantity')->nullable()->after('space_package');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bizfest_partner_inquiries')) {
            return;
        }

        Schema::table('bizfest_partner_inquiries', function (Blueprint $table) {
            $columns = ['interests', 'booth_package', 'space_package', 'booth_quantity'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('bizfest_partner_inquiries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
