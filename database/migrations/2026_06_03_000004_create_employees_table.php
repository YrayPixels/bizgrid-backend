<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('staff_id', 40);
                $table->string('first_name', 80);
                $table->string('last_name', 80);
                $table->string('email')->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('role', 80);
                $table->string('department', 80)->nullable();
                $table->string('employment_type', 40)->default('full_time');
                $table->date('hire_date')->nullable();
                $table->decimal('salary', 12, 2)->nullable();
                $table->string('status', 40)->default('active');
                $table->text('address')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['school_id', 'staff_id']);
                $table->index(['school_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
