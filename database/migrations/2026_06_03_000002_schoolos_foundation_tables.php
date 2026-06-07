<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_classes')) {
            Schema::create('school_classes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name', 80);
                $table->string('section', 40)->nullable();
                $table->text('description')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['school_id', 'name', 'section']);
            });
        }

        if (! Schema::hasTable('subjects')) {
            Schema::create('subjects', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name', 80);
                $table->string('code', 20)->nullable();
                $table->text('description')->nullable();
                $table->timestamps();
                $table->unique(['school_id', 'name']);
            });
        }

        if (! Schema::hasTable('academic_terms')) {
            Schema::create('academic_terms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name', 80);
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->boolean('is_current')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('class_subject')) {
            Schema::create('class_subject', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete();
                $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['school_class_id', 'subject_id']);
            });
        }

        if (Schema::hasTable('students') && ! Schema::hasColumn('students', 'school_class_id')) {
            Schema::table('students', function (Blueprint $table) {
                $table->foreignId('school_class_id')->nullable()->after('school_id')->constrained('school_classes')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('attendance_records')) {
            Schema::create('attendance_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('school_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->date('attendance_date');
                $table->string('status', 20)->default('present');
                $table->text('notes')->nullable();
                $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['student_id', 'attendance_date']);
            });
        }

        if (! Schema::hasTable('fee_categories')) {
            Schema::create('fee_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name', 80);
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('billing_cycle', 20)->default('term');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['school_id', 'name']);
            });
        }

        if (! Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('fee_category_id')->nullable()->constrained('fee_categories')->nullOnDelete();
                $table->string('invoice_number', 40);
                $table->decimal('amount', 12, 2);
                $table->date('due_date');
                $table->string('status', 20)->default('pending');
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['school_id', 'invoice_number']);
            });
        }

        if (! Schema::hasTable('invoice_payments')) {
            Schema::create('invoice_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->decimal('amount', 12, 2);
                $table->date('paid_at');
                $table->string('method', 30)->default('cash');
                $table->string('reference', 80)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('fee_categories');
        Schema::dropIfExists('attendance_records');

        if (Schema::hasTable('students') && Schema::hasColumn('students', 'school_class_id')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropConstrainedForeignId('school_class_id');
            });
        }

        Schema::dropIfExists('class_subject');
        Schema::dropIfExists('academic_terms');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('school_classes');
    }
};
