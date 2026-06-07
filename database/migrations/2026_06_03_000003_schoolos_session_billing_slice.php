<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('academic_sessions')) {
            Schema::create('academic_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name', 40);
                $table->date('start_date');
                $table->date('end_date');
                $table->string('status', 20)->default('planned');
                $table->timestamps();
                $table->unique(['school_id', 'name']);
            });
        }

        if (Schema::hasTable('academic_terms') && ! Schema::hasColumn('academic_terms', 'academic_session_id')) {
            Schema::table('academic_terms', function (Blueprint $table) {
                $table->foreignId('academic_session_id')
                    ->nullable()
                    ->after('school_id')
                    ->constrained('academic_sessions')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasTable('student_enrollments')) {
            Schema::create('student_enrollments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
                $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete();
                $table->string('status', 20)->default('active');
                $table->date('enrolled_at')->nullable();
                $table->timestamps();
                $table->unique(['student_id', 'academic_session_id']);
            });
        }

        if (! Schema::hasTable('fee_templates')) {
            Schema::create('fee_templates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('fee_category_id')->nullable()->constrained('fee_categories')->nullOnDelete();
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->decimal('amount', 12, 2);
                $table->string('currency', 3)->default('NGN');
                $table->boolean('is_recurring')->default(false);
                $table->boolean('is_optional')->default(false);
                $table->string('status', 20)->default('active');
                $table->timestamps();
                $table->unique(['school_id', 'name']);
            });
        }

        if (! Schema::hasTable('fee_assignments')) {
            Schema::create('fee_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('fee_template_id')->constrained('fee_templates')->cascadeOnDelete();
                $table->foreignId('academic_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
                $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms')->nullOnDelete();
                $table->string('assignment_type', 20);
                $table->unsignedBigInteger('assignment_id')->nullable();
                $table->date('due_date');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (! Schema::hasColumn('invoices', 'academic_session_id')) {
                    $table->foreignId('academic_session_id')->nullable()->after('fee_category_id')->constrained('academic_sessions')->nullOnDelete();
                }
                if (! Schema::hasColumn('invoices', 'academic_term_id')) {
                    $table->foreignId('academic_term_id')->nullable()->after('academic_session_id')->constrained('academic_terms')->nullOnDelete();
                }
                if (! Schema::hasColumn('invoices', 'fee_assignment_id')) {
                    $table->foreignId('fee_assignment_id')->nullable()->after('academic_term_id')->constrained('fee_assignments')->nullOnDelete();
                }
            });
        }

        if (! Schema::hasTable('invoice_items')) {
            Schema::create('invoice_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->foreignId('fee_template_id')->nullable()->constrained('fee_templates')->nullOnDelete();
                $table->string('description', 160);
                $table->decimal('amount', 12, 2);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (Schema::hasColumn('invoices', 'fee_assignment_id')) {
                    $table->dropConstrainedForeignId('fee_assignment_id');
                }
                if (Schema::hasColumn('invoices', 'academic_term_id')) {
                    $table->dropConstrainedForeignId('academic_term_id');
                }
                if (Schema::hasColumn('invoices', 'academic_session_id')) {
                    $table->dropConstrainedForeignId('academic_session_id');
                }
            });
        }

        Schema::dropIfExists('fee_assignments');
        Schema::dropIfExists('fee_templates');
        Schema::dropIfExists('student_enrollments');

        if (Schema::hasTable('academic_terms') && Schema::hasColumn('academic_terms', 'academic_session_id')) {
            Schema::table('academic_terms', function (Blueprint $table) {
                $table->dropConstrainedForeignId('academic_session_id');
            });
        }

        Schema::dropIfExists('academic_sessions');
    }
};
