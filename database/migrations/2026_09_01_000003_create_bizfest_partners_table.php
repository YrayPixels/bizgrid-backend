<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bizfest_partners')) {
            Schema::create('bizfest_partners', function (Blueprint $table) {
                $table->id();
                $table->string('programme', 40)->default('bizfest-1')->index();
                $table->string('name', 200);
                $table->string('label', 120)->nullable();
                $table->string('logo_url', 500)->nullable();
                $table->string('website_url', 500)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->index(['programme', 'is_active', 'sort_order']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bizfest_partners');
    }
};
