<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('dealie_chat_mode')->default('full_ai')->after('dealie_vendor_id');
            $table->json('dealie_chat_config')->nullable()->after('dealie_chat_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['dealie_chat_mode', 'dealie_chat_config']);
        });
    }
};
