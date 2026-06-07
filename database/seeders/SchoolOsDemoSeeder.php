<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SchoolOsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $owner = User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'email_verified_at' => $now,
                'password' => Hash::make('password'),
            ],
        );

        $schoolId = $this->upsertReturningId('schools', ['slug' => 'greenfield-harmony'], [
            'name' => 'Greenfield Harmony Academy',
            'motto' => 'Learning in harmony',
            'contact_email' => 'admin@greenfield-harmony.test',
            'created_by' => $owner->id,
            'status' => 'active',
        ]);

        DB::table('school_user')->updateOrInsert(
            ['school_id' => $schoolId, 'user_id' => $owner->id],
            ['role' => 'owner', 'created_at' => $now, 'updated_at' => $now],
        );

        $roleNames = ['Principal', 'Teacher', 'Accountant', 'School Nurse', 'Administrative Officer'];
        foreach ($roleNames as $roleName) {
            $this->upsertReturningId('school_employee_roles', [
                'school_id' => $schoolId,
                'name' => $roleName,
            ]);
        }

        $departmentNames = ['Administration', 'Academics', 'Finance', 'Health', 'Operations'];
        foreach ($departmentNames as $departmentName) {
            $this->upsertReturningId('school_employee_departments', [
                'school_id' => $schoolId,
                'name' => $departmentName,
            ]);
        }

        $primaryOneId = $this->upsertReturningId('school_classes', [
            'school_id' => $schoolId,
            'name' => 'Primary 1',
            'section' => 'A',
        ], [
            'description' => 'Primary 1 section A',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $primaryTwoId = $this->upsertReturningId('school_classes', [
            'school_id' => $schoolId,
            'name' => 'Primary 2',
            'section' => 'A',
        ], [
            'description' => 'Primary 2 section A',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $mathematicsId = $this->upsertReturningId('subjects', [
            'school_id' => $schoolId,
            'name' => 'Mathematics',
        ], [
            'code' => 'MATH',
            'description' => 'Core numeracy and problem solving',
        ]);

        $englishId = $this->upsertReturningId('subjects', [
            'school_id' => $schoolId,
            'name' => 'English Language',
        ], [
            'code' => 'ENG',
            'description' => 'Reading, writing, and communication',
        ]);

        foreach ([$primaryOneId, $primaryTwoId] as $classId) {
            foreach ([$mathematicsId, $englishId] as $subjectId) {
                DB::table('class_subject')->updateOrInsert(
                    ['school_class_id' => $classId, 'subject_id' => $subjectId],
                    ['school_id' => $schoolId, 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        $sessionId = $this->upsertReturningId('academic_sessions', [
            'school_id' => $schoolId,
            'name' => '2026/2027',
        ], [
            'start_date' => '2026-09-01',
            'end_date' => '2027-07-31',
            'status' => 'active',
        ]);

        $firstTermId = $this->upsertReturningId('academic_terms', [
            'school_id' => $schoolId,
            'academic_session_id' => $sessionId,
            'name' => 'First Term',
        ], [
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-18',
            'is_current' => true,
        ]);

        $secondTermId = $this->upsertReturningId('academic_terms', [
            'school_id' => $schoolId,
            'academic_session_id' => $sessionId,
            'name' => 'Second Term',
        ], [
            'start_date' => '2027-01-11',
            'end_date' => '2027-04-09',
            'is_current' => false,
        ]);

        $students = [
            [
                'admission_number' => 'GHA-STD-001',
                'first_name' => 'Amina',
                'last_name' => 'Bello',
                'gender' => 'female',
                'class_id' => $primaryOneId,
                'guardian_name' => 'Maryam Bello',
                'guardian_phone' => '+2348010000001',
                'guardian_email' => 'maryam.bello@example.com',
            ],
            [
                'admission_number' => 'GHA-STD-002',
                'first_name' => 'Daniel',
                'last_name' => 'Okafor',
                'gender' => 'male',
                'class_id' => $primaryTwoId,
                'guardian_name' => 'Chinedu Okafor',
                'guardian_phone' => '+2348010000002',
                'guardian_email' => 'chinedu.okafor@example.com',
            ],
        ];

        $studentIds = [];
        foreach ($students as $student) {
            $studentId = $this->upsertReturningId('students', [
                'school_id' => $schoolId,
                'admission_number' => $student['admission_number'],
            ], [
                'first_name' => $student['first_name'],
                'last_name' => $student['last_name'],
                'date_of_birth' => '2018-05-12',
                'gender' => $student['gender'],
                'class_level' => null,
                'school_class_id' => $student['class_id'],
                'guardian_name' => $student['guardian_name'],
                'guardian_phone' => $student['guardian_phone'],
                'guardian_email' => $student['guardian_email'],
                'enrollment_date' => '2026-09-01',
                'status' => 'enrolled',
                'notes' => null,
                'created_by' => $owner->id,
            ]);

            $studentIds[] = $studentId;
            $this->upsertReturningId('student_enrollments', [
                'student_id' => $studentId,
                'academic_session_id' => $sessionId,
            ], [
                'school_id' => $schoolId,
                'school_class_id' => $student['class_id'],
                'status' => 'active',
                'enrolled_at' => '2026-09-01',
            ]);
        }

        $employees = [
            [
                'staff_id' => 'GHA-001',
                'first_name' => 'Grace',
                'last_name' => 'Adebayo',
                'email' => 'grace.adebayo@example.com',
                'phone' => '+2348020000001',
                'role' => 'Principal',
                'department' => 'Administration',
                'salary' => 450000,
            ],
            [
                'staff_id' => 'GHA-002',
                'first_name' => 'Samuel',
                'last_name' => 'Ibrahim',
                'email' => 'samuel.ibrahim@example.com',
                'phone' => '+2348020000002',
                'role' => 'Teacher',
                'department' => 'Academics',
                'salary' => 220000,
            ],
        ];

        foreach ($employees as $employee) {
            $this->upsertReturningId('employees', [
                'school_id' => $schoolId,
                'staff_id' => $employee['staff_id'],
            ], [
                'first_name' => $employee['first_name'],
                'last_name' => $employee['last_name'],
                'email' => $employee['email'],
                'phone' => $employee['phone'],
                'role' => $employee['role'],
                'department' => $employee['department'],
                'employment_type' => 'full_time',
                'hire_date' => '2026-08-15',
                'salary' => $employee['salary'],
                'status' => 'active',
                'address' => 'Greenfield Harmony Academy staff quarters',
                'notes' => null,
                'created_by' => $owner->id,
            ]);
        }

        foreach ($studentIds as $studentId) {
            $this->upsertReturningId('attendance_records', [
                'student_id' => $studentId,
                'attendance_date' => '2026-09-07',
            ], [
                'school_id' => $schoolId,
                'school_class_id' => $studentId === $studentIds[0] ? $primaryOneId : $primaryTwoId,
                'status' => 'present',
                'notes' => null,
                'marked_by' => $owner->id,
            ]);
        }

        $tuitionCategoryId = $this->upsertReturningId('fee_categories', [
            'school_id' => $schoolId,
            'name' => 'Tuition',
        ], [
            'amount' => 150000,
            'billing_cycle' => 'term',
            'description' => 'Core term tuition fee',
            'is_active' => true,
        ]);

        $tuitionTemplateId = $this->upsertReturningId('fee_templates', [
            'school_id' => $schoolId,
            'name' => 'First Term Tuition',
        ], [
            'fee_category_id' => $tuitionCategoryId,
            'description' => 'Tuition for first term 2026/2027',
            'amount' => 150000,
            'currency' => 'NGN',
            'is_recurring' => true,
            'is_optional' => false,
            'status' => 'active',
        ]);

        $assignmentId = $this->upsertReturningId('fee_assignments', [
            'school_id' => $schoolId,
            'fee_template_id' => $tuitionTemplateId,
            'academic_session_id' => $sessionId,
            'academic_term_id' => $firstTermId,
            'assignment_type' => 'class',
            'assignment_id' => $primaryOneId,
        ], [
            'due_date' => '2026-09-30',
            'created_by' => $owner->id,
        ]);

        $invoiceId = $this->upsertReturningId('invoices', [
            'school_id' => $schoolId,
            'invoice_number' => 'GHA-INV-2026-001',
        ], [
            'student_id' => $studentIds[0],
            'fee_category_id' => $tuitionCategoryId,
            'academic_session_id' => $sessionId,
            'academic_term_id' => $firstTermId,
            'fee_assignment_id' => $assignmentId,
            'amount' => 150000,
            'due_date' => '2026-09-30',
            'status' => 'partial',
            'notes' => 'Demo invoice seeded for finance dashboard',
            'created_by' => $owner->id,
        ]);

        $this->upsertReturningId('invoice_items', [
            'invoice_id' => $invoiceId,
            'description' => 'First Term Tuition',
        ], [
            'fee_template_id' => $tuitionTemplateId,
            'amount' => 150000,
        ]);

        $this->upsertReturningId('invoice_payments', [
            'invoice_id' => $invoiceId,
            'reference' => 'GHA-PAY-001',
        ], [
            'school_id' => $schoolId,
            'amount' => 50000,
            'paid_at' => '2026-09-15',
            'method' => 'bank_transfer',
            'notes' => 'Initial part payment',
            'recorded_by' => $owner->id,
        ]);

        $this->upsertReturningId('school_events', [
            'school_id' => $schoolId,
            'title' => 'First Term Resumption',
            'start_at' => '2026-09-01 08:00:00',
        ], [
            'academic_session_id' => $sessionId,
            'academic_term_id' => $firstTermId,
            'event_type' => 'academic',
            'end_at' => '2026-09-01 14:00:00',
            'all_day' => false,
            'location' => 'Main campus',
            'audience' => 'All students',
            'description' => 'Students resume for the first term.',
            'created_by' => $owner->id,
        ]);

        $this->upsertReturningId('school_events', [
            'school_id' => $schoolId,
            'title' => 'Second Term Planning',
            'start_at' => '2027-01-04 10:00:00',
        ], [
            'academic_session_id' => $sessionId,
            'academic_term_id' => $secondTermId,
            'event_type' => 'meeting',
            'end_at' => '2027-01-04 12:00:00',
            'all_day' => false,
            'location' => 'Conference room',
            'audience' => 'Staff',
            'description' => 'Staff planning meeting before second term starts.',
            'created_by' => $owner->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $keys
     * @param  array<string, mixed>  $values
     */
    private function upsertReturningId(string $table, array $keys, array $values = []): int
    {
        $now = now();
        $row = DB::table($table)->where($keys)->first();

        if ($row) {
            DB::table($table)->where('id', $row->id)->update([
                ...$values,
                'updated_at' => $now,
            ]);

            return (int) $row->id;
        }

        return (int) DB::table($table)->insertGetId([
            ...$keys,
            ...$values,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
