<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_messages')) {
            Schema::create('school_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('channel', 20);
                $table->string('audience', 20);
                $table->string('title', 160);
                $table->text('body');
                $table->unsignedInteger('recipient_count')->default(0);
                $table->json('recipients')->nullable();
                $table->string('status', 40)->default('sent');
                $table->timestamp('sent_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['school_id', 'created_at']);
                $table->index(['school_id', 'channel']);
                $table->index(['school_id', 'audience']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_messages');
    }
};
