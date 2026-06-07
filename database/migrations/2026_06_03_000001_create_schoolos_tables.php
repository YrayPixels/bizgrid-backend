<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('schools')) {
            Schema::create('schools', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 32)->unique();
                $table->string('motto', 200)->nullable();
                $table->string('contact_email')->nullable();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('school_user')) {
            Schema::create('school_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role')->default('owner');
                $table->timestamps();
                $table->unique(['school_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('students')) {
            Schema::create('students', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('admission_number', 40);
                $table->string('first_name', 80);
                $table->string('last_name', 80);
                $table->date('date_of_birth')->nullable();
                $table->string('gender', 20)->nullable();
                $table->string('class_level', 40)->nullable();
                $table->string('guardian_name', 120)->nullable();
                $table->string('guardian_phone', 40)->nullable();
                $table->string('guardian_email')->nullable();
                $table->date('enrollment_date');
                $table->string('status')->default('enrolled');
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['school_id', 'admission_number']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
        Schema::dropIfExists('school_user');
        Schema::dropIfExists('schools');
    }
};
