<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bizfest_partner_inquiries')) {
            Schema::create('bizfest_partner_inquiries', function (Blueprint $table) {
                $table->id();
                $table->string('programme', 40)->default('bizfest-1')->index();
                $table->string('inquiry_type', 20)->index();
                $table->string('company_name', 200);
                $table->string('contact_name', 160);
                $table->string('email')->index();
                $table->string('phone', 40);
                $table->string('tier_interest', 80)->nullable();
                $table->text('message')->nullable();
                $table->string('status', 30)->default('new')->index();
                $table->string('utm_source', 80)->nullable();
                $table->string('utm_medium', 80)->nullable();
                $table->string('utm_campaign', 120)->nullable();
                $table->timestamps();

                $table->index(['programme', 'inquiry_type', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bizfest_partner_inquiries');
    }
};
