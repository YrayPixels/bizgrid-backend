<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_templates', function (Blueprint $table) {
            $table->json('industries')->nullable()->after('default_palette');
            $table->json('tone_tags')->nullable()->after('industries');
            $table->json('visual_tags')->nullable()->after('tone_tags');
            $table->json('product_types')->nullable()->after('visual_tags');
            $table->json('required_content_slots')->nullable()->after('product_types');
            $table->json('optional_content_slots')->nullable()->after('required_content_slots');
            $table->string('origin', 40)->default('platform')->after('optional_content_slots');
            $table->string('base_template_id', 60)->nullable()->after('origin');
            $table->string('generation_status', 40)->default('active')->after('base_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_templates', function (Blueprint $table) {
            $table->dropColumn([
                'industries',
                'tone_tags',
                'visual_tags',
                'product_types',
                'required_content_slots',
                'optional_content_slots',
                'origin',
                'base_template_id',
                'generation_status',
            ]);
        });
    }
};
