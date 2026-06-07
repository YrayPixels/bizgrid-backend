<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_events')) {
            Schema::create('school_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('academic_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
                $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms')->nullOnDelete();
                $table->string('title', 160);
                $table->string('event_type', 40)->default('event');
                $table->dateTime('start_at');
                $table->dateTime('end_at')->nullable();
                $table->boolean('all_day')->default(false);
                $table->string('location', 160)->nullable();
                $table->string('audience', 80)->nullable();
                $table->text('description')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['school_id', 'start_at']);
                $table->index(['school_id', 'event_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_events');
    }
};
