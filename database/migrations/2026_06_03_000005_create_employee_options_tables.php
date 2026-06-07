<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_employee_roles')) {
            Schema::create('school_employee_roles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name', 80);
                $table->timestamps();

                $table->unique(['school_id', 'name']);
            });
        }

        if (! Schema::hasTable('school_employee_departments')) {
            Schema::create('school_employee_departments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->string('name', 80);
                $table->timestamps();

                $table->unique(['school_id', 'name']);
            });
        }

        if (Schema::hasTable('employees')) {
            $now = now();

            DB::table('employees')
                ->select('school_id', 'role as name')
                ->whereNotNull('role')
                ->where('role', '!=', '')
                ->distinct()
                ->orderBy('school_id')
                ->chunk(100, function ($roles) use ($now) {
                    foreach ($roles as $role) {
                        DB::table('school_employee_roles')->updateOrInsert(
                            ['school_id' => $role->school_id, 'name' => $role->name],
                            ['updated_at' => $now, 'created_at' => $now],
                        );
                    }
                });

            DB::table('employees')
                ->select('school_id', 'department as name')
                ->whereNotNull('department')
                ->where('department', '!=', '')
                ->distinct()
                ->orderBy('school_id')
                ->chunk(100, function ($departments) use ($now) {
                    foreach ($departments as $department) {
                        DB::table('school_employee_departments')->updateOrInsert(
                            ['school_id' => $department->school_id, 'name' => $department->name],
                            ['updated_at' => $now, 'created_at' => $now],
                        );
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_employee_departments');
        Schema::dropIfExists('school_employee_roles');
    }
};
