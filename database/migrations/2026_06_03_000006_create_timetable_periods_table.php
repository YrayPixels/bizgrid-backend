<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('timetable_periods')) {
            Schema::create('timetable_periods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
                $table->foreignId('academic_term_id')->constrained('academic_terms')->cascadeOnDelete();
                $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete();
                $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
                $table->foreignId('teacher_employee_id')->constrained('employees')->cascadeOnDelete();
                $table->string('weekday', 10);
                $table->time('start_time');
                $table->time('end_time');
                $table->string('room', 80)->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 20)->default('active');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(
                    ['school_id', 'academic_session_id', 'academic_term_id', 'weekday'],
                    'tt_periods_school_session_term_weekday_idx',
                );
                $table->index(['teacher_employee_id', 'weekday'], 'tt_periods_teacher_weekday_idx');
                $table->index(['school_class_id', 'weekday'], 'tt_periods_class_weekday_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_periods');
    }
};
