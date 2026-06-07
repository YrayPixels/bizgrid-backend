<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSchoolAccess;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Employee;
use App\Models\FeeCategory;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SchoolOsImportController extends Controller
{
    use AuthorizesSchoolAccess;

    private const SHEETS = [
        'classes',
        'subjects',
        'classSubjects',
        'academicSessions',
        'terms',
        'students',
        'enrollments',
        'employees',
        'feeCategories',
        'feeTemplates',
    ];

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);

        $payload = $request->validate([
            'classes' => ['nullable', 'array', 'max:500'],
            'subjects' => ['nullable', 'array', 'max:500'],
            'classSubjects' => ['nullable', 'array', 'max:2000'],
            'academicSessions' => ['nullable', 'array', 'max:100'],
            'terms' => ['nullable', 'array', 'max:500'],
            'students' => ['nullable', 'array', 'max:2000'],
            'enrollments' => ['nullable', 'array', 'max:2000'],
            'employees' => ['nullable', 'array', 'max:1000'],
            'feeCategories' => ['nullable', 'array', 'max:500'],
            'feeTemplates' => ['nullable', 'array', 'max:500'],
        ]);

        $rows = $this->normalisePayload($payload);
        $errors = $this->validateImportRows($school, $rows);

        if ($errors !== []) {
            return response()->json([
                'message' => 'Fix the highlighted rows in the import file and upload it again.',
                'errors' => $errors,
            ], 422);
        }

        $summary = DB::transaction(function () use ($request, $school, $rows) {
            $summary = $this->emptySummary();

            $this->importClasses($school, $rows['classes'], $summary);
            $this->importSubjects($school, $rows['subjects'], $summary);
            $this->importClassSubjects($school, $rows['classSubjects'], $summary);
            $this->importAcademicSessions($school, $rows['academicSessions'], $summary);
            $this->importTerms($school, $rows['terms'], $summary);
            $this->importStudents($request, $school, $rows['students'], $summary);
            $this->importEnrollments($school, $rows['enrollments'], $summary);
            $this->importEmployees($request, $school, $rows['employees'], $summary);
            $this->importFeeCategories($school, $rows['feeCategories'], $summary);
            $this->importFeeTemplates($school, $rows['feeTemplates'], $summary);

            return $summary;
        });

        return response()->json([
            'ok' => true,
            'summary' => $summary,
        ]);
    }

    private function normalisePayload(array $payload): array
    {
        $rows = [];

        foreach (self::SHEETS as $sheet) {
            $rows[$sheet] = collect($payload[$sheet] ?? [])
                ->filter(fn ($row) => is_array($row) && $this->hasImportValues($row))
                ->map(fn ($row, $index) => $this->cleanRow($row, $index))
                ->values()
                ->all();
        }

        return $rows;
    }

    private function validateImportRows(School $school, array $rows): array
    {
        $errors = [];

        $this->validateSheet($rows['classes'], 'Classes', [
            'name' => ['required', 'string', 'max:80'],
            'section' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sortOrder' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'isActive' => ['nullable', 'boolean'],
        ], $errors);

        $this->validateSheet($rows['subjects'], 'Subjects', [
            'name' => ['required', 'string', 'max:80'],
            'code' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], $errors);

        $this->validateSheet($rows['classSubjects'], 'Class Subjects', [
            'className' => ['required', 'string', 'max:80'],
            'classSection' => ['nullable', 'string', 'max:40'],
            'subjectName' => ['required', 'string', 'max:80'],
        ], $errors);

        $this->validateSheet($rows['academicSessions'], 'Academic Sessions', [
            'name' => ['required', 'string', 'max:40'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'status' => ['required', Rule::in(['planned', 'active', 'archived'])],
        ], $errors);

        $this->validateSheet($rows['terms'], 'Terms', [
            'academicSessionName' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:80'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'isCurrent' => ['nullable', 'boolean'],
        ], $errors);

        $this->validateSheet($rows['enrollments'], 'Enrollments', [
            'admissionNumber' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_/]+$/'],
            'academicSessionName' => ['required', 'string', 'max:40'],
            'className' => ['required', 'string', 'max:80'],
            'classSection' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', Rule::in(['active', 'promoted', 'transferred', 'graduated', 'withdrawn'])],
            'enrolledAt' => ['nullable', 'date'],
        ], $errors);

        $this->validateSheet($rows['students'], 'Students', [
            'admissionNumber' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_/]+$/'],
            'firstName' => ['required', 'string', 'max:80'],
            'lastName' => ['required', 'string', 'max:80'],
            'dateOfBirth' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'className' => ['nullable', 'string', 'max:80'],
            'classSection' => ['nullable', 'string', 'max:40'],
            'guardianName' => ['nullable', 'string', 'max:120'],
            'guardianPhone' => ['nullable', 'string', 'max:40'],
            'guardianEmail' => ['nullable', 'email', 'max:255'],
            'enrollmentDate' => ['required', 'date'],
            'status' => ['required', Rule::in(['enrolled', 'withdrawn', 'graduated', 'suspended'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], $errors);

        $this->validateSheet($rows['employees'], 'Employees', [
            'staffId' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_/]+$/'],
            'firstName' => ['required', 'string', 'max:80'],
            'lastName' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', 'string', 'max:80'],
            'department' => ['nullable', 'string', 'max:80'],
            'employmentType' => ['required', Rule::in(['full_time', 'part_time', 'contract', 'temporary'])],
            'hireDate' => ['nullable', 'date'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'status' => ['required', Rule::in(['active', 'on_leave', 'suspended', 'terminated'])],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], $errors);

        $this->validateSheet($rows['feeCategories'], 'Fee Categories', [
            'name' => ['required', 'string', 'max:80'],
            'amount' => ['required', 'numeric', 'min:0'],
            'billingCycle' => ['required', Rule::in(['term', 'monthly', 'one_time'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'isActive' => ['nullable', 'boolean'],
        ], $errors);

        $this->validateSheet($rows['feeTemplates'], 'Fee Templates', [
            'name' => ['required', 'string', 'max:120'],
            'feeCategoryName' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'isRecurring' => ['nullable', 'boolean'],
            'isOptional' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ], $errors);

        $this->validateDuplicateKeys($rows['classes'], 'Classes', 'classKey', 'name + section', $errors);
        $this->validateDuplicateKeys($rows['subjects'], 'Subjects', 'name', 'name', $errors);
        $this->validateDuplicateClassSubjects($rows['classSubjects'], $errors);
        $this->validateDuplicateKeys($rows['academicSessions'], 'Academic Sessions', 'name', 'name', $errors);
        $this->validateDuplicateKeys($rows['students'], 'Students', 'admissionNumber', 'admission number', $errors);
        $this->validateDuplicateEnrollments($rows['enrollments'], $errors);
        $this->validateDuplicateKeys($rows['employees'], 'Employees', 'staffId', 'staff ID', $errors);
        $this->validateDuplicateKeys($rows['feeCategories'], 'Fee Categories', 'name', 'name', $errors);
        $this->validateDuplicateKeys($rows['feeTemplates'], 'Fee Templates', 'name', 'name', $errors);

        $this->validateReferences($school, $rows, $errors);

        return $errors;
    }

    private function validateSheet(array $rows, string $sheet, array $rules, array &$errors): void
    {
        foreach ($rows as $row) {
            $validator = Validator::make($row, $rules);

            foreach ($validator->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $errors[] = $this->error($sheet, $row, $field, $message);
                }
            }
        }
    }

    private function validateReferences(School $school, array $rows, array &$errors): void
    {
        $classKeys = SchoolClass::where('school_id', $school->id)
            ->get(['name', 'section'])
            ->map(fn (SchoolClass $class) => $this->classKey($class->name, $class->section))
            ->all();

        foreach ($rows['classes'] as $row) {
            $classKeys[] = $this->classKey($row['name'] ?? null, $row['section'] ?? null);
        }

        $sessionNames = AcademicSession::where('school_id', $school->id)->pluck('name')->map(fn ($name) => $this->key($name))->all();
        foreach ($rows['academicSessions'] as $row) {
            $sessionNames[] = $this->key($row['name'] ?? null);
        }

        $subjectNames = Subject::where('school_id', $school->id)->pluck('name')->map(fn ($name) => $this->key($name))->all();
        foreach ($rows['subjects'] as $row) {
            $subjectNames[] = $this->key($row['name'] ?? null);
        }

        $categoryNames = FeeCategory::where('school_id', $school->id)->pluck('name')->map(fn ($name) => $this->key($name))->all();
        foreach ($rows['feeCategories'] as $row) {
            $categoryNames[] = $this->key($row['name'] ?? null);
        }

        $admissionNumbers = Student::where('school_id', $school->id)
            ->pluck('admission_number')
            ->map(fn ($number) => $this->key($number))
            ->all();
        foreach ($rows['students'] as $row) {
            $admissionNumbers[] = $this->key($row['admissionNumber'] ?? null);
        }

        foreach ($rows['classSubjects'] as $row) {
            if (! in_array($this->classKey($row['className'] ?? null, $row['classSection'] ?? null), $classKeys, true)) {
                $errors[] = $this->error('Class Subjects', $row, 'className', 'No matching class was found. Add it on the Classes sheet first.');
            }

            if (! in_array($this->key($row['subjectName'] ?? null), $subjectNames, true)) {
                $errors[] = $this->error('Class Subjects', $row, 'subjectName', 'No matching subject was found. Add it on the Subjects sheet first.');
            }
        }

        foreach ($rows['students'] as $row) {
            if (! empty($row['className']) && ! in_array($this->classKey($row['className'], $row['classSection'] ?? null), $classKeys, true)) {
                $errors[] = $this->error('Students', $row, 'className', 'No matching class was found. Add it on the Classes sheet first.');
            }
        }

        foreach ($rows['terms'] as $row) {
            if (! empty($row['academicSessionName']) && ! in_array($this->key($row['academicSessionName']), $sessionNames, true)) {
                $errors[] = $this->error('Terms', $row, 'academicSessionName', 'No matching academic session was found. Add it on the Academic Sessions sheet first.');
            }
        }

        foreach ($rows['feeTemplates'] as $row) {
            if (! empty($row['feeCategoryName']) && ! in_array($this->key($row['feeCategoryName']), $categoryNames, true)) {
                $errors[] = $this->error('Fee Templates', $row, 'feeCategoryName', 'No matching fee category was found. Add it on the Fee Categories sheet first.');
            }
        }

        foreach ($rows['enrollments'] as $row) {
            if (! in_array($this->key($row['admissionNumber'] ?? null), $admissionNumbers, true)) {
                $errors[] = $this->error('Enrollments', $row, 'admissionNumber', 'No matching student was found. Add them on the Students sheet first.');
            }

            if (! in_array($this->key($row['academicSessionName'] ?? null), $sessionNames, true)) {
                $errors[] = $this->error('Enrollments', $row, 'academicSessionName', 'No matching academic session was found. Add it on the Academic Sessions sheet first.');
            }

            if (! in_array($this->classKey($row['className'] ?? null, $row['classSection'] ?? null), $classKeys, true)) {
                $errors[] = $this->error('Enrollments', $row, 'className', 'No matching class was found. Add it on the Classes sheet first.');
            }
        }
    }

    private function validateDuplicateClassSubjects(array $rows, array &$errors): void
    {
        $seen = [];

        foreach ($rows as $row) {
            $key = $this->classKey($row['className'] ?? null, $row['classSection'] ?? null).'|'.$this->key($row['subjectName'] ?? null);

            if ($key === '|') {
                continue;
            }

            if (isset($seen[$key])) {
                $errors[] = $this->error('Class Subjects', $row, 'subjectName', 'Duplicate class and subject assignment in this upload.');
            }

            $seen[$key] = true;
        }
    }

    private function validateDuplicateEnrollments(array $rows, array &$errors): void
    {
        $seen = [];

        foreach ($rows as $row) {
            $key = $this->key($row['admissionNumber'] ?? null).'|'.$this->key($row['academicSessionName'] ?? null);

            if ($key === '|') {
                continue;
            }

            if (isset($seen[$key])) {
                $errors[] = $this->error('Enrollments', $row, 'admissionNumber', 'Duplicate student and session enrollment in this upload.');
            }

            $seen[$key] = true;
        }
    }

    private function validateDuplicateKeys(array $rows, string $sheet, string $field, string $label, array &$errors): void
    {
        $seen = [];

        foreach ($rows as $row) {
            $key = $field === 'classKey'
                ? $this->classKey($row['name'] ?? null, $row['section'] ?? null)
                : $this->key($row[$field] ?? null);

            if ($key === '') {
                continue;
            }

            if (isset($seen[$key])) {
                $errors[] = $this->error($sheet, $row, $field, "Duplicate {$label} in this upload.");
            }

            $seen[$key] = true;
        }
    }

    private function importClasses(School $school, array $rows, array &$summary): void
    {
        foreach ($rows as $row) {
            $class = SchoolClass::firstOrNew([
                'school_id' => $school->id,
                'name' => $row['name'],
                'section' => $row['section'] ?? null,
            ]);
            $this->count($summary, 'classes', ! $class->exists);

            $class->fill([
                'description' => $row['description'] ?? null,
                'sort_order' => $row['sortOrder'] ?? 0,
                'is_active' => $row['isActive'] ?? true,
            ])->save();
        }
    }

    private function importSubjects(School $school, array $rows, array &$summary): void
    {
        foreach ($rows as $row) {
            $subject = $school->subjects()->firstOrNew(['name' => $row['name']]);
            $this->count($summary, 'subjects', ! $subject->exists);
            $subject->fill([
                'code' => $row['code'] ?? null,
                'description' => $row['description'] ?? null,
            ])->save();
        }
    }

    private function importClassSubjects(School $school, array $rows, array &$summary): void
    {
        foreach ($rows as $row) {
            $classId = $this->classId($school, $row['className'], $row['classSection'] ?? null);
            $subjectId = $this->subjectId($school, $row['subjectName']);

            if (! $classId || ! $subjectId) {
                continue;
            }

            $exists = DB::table('class_subject')
                ->where('school_class_id', $classId)
                ->where('subject_id', $subjectId)
                ->exists();

            if ($exists) {
                $this->count($summary, 'classSubjects', false);

                continue;
            }

            DB::table('class_subject')->insert([
                'school_id' => $school->id,
                'school_class_id' => $classId,
                'subject_id' => $subjectId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->count($summary, 'classSubjects', true);
        }
    }

    private function importAcademicSessions(School $school, array $rows, array &$summary): void
    {
        foreach ($rows as $row) {
            $session = $school->academicSessions()->firstOrNew(['name' => $row['name']]);
            $this->count($summary, 'academicSessions', ! $session->exists);
            $session->fill([
                'start_date' => $row['startDate'],
                'end_date' => $row['endDate'],
                'status' => $row['status'],
            ])->save();
        }
    }

    private function importTerms(School $school, array $rows, array &$summary): void
    {
        foreach ($rows as $row) {
            $sessionId = $this->sessionId($school, $row['academicSessionName'] ?? null);
            $term = AcademicTerm::firstOrNew([
                'school_id' => $school->id,
                'academic_session_id' => $sessionId,
                'name' => $row['name'],
            ]);
            $this->count($summary, 'terms', ! $term->exists);
            $term->fill([
                'start_date' => $row['startDate'] ?? null,
                'end_date' => $row['endDate'] ?? null,
                'is_current' => $row['isCurrent'] ?? false,
            ])->save();
        }
    }

    private function importStudents(Request $request, School $school, array $rows, array &$summary): void
    {
        foreach ($rows as $row) {
            $student = Student::firstOrNew([
                'school_id' => $school->id,
                'admission_number' => $row['admissionNumber'],
            ]);
            $this->count($summary, 'students', ! $student->exists);
            $student->fill([
                'first_name' => $row['firstName'],
                'last_name' => $row['lastName'],
                'date_of_birth' => $row['dateOfBirth'] ?? null,
                'gender' => $row['gender'] ?? null,
                'class_level' => $row['className'] ?? null,
                'school_class_id' => $this->classId($school, $row['className'] ?? null, $row['classSection'] ?? null),
                'guardian_name' => $row['guardianName'] ?? null,
                'guardian_phone' => $row['guardianPhone'] ?? null,
                'guardian_email' => $row['guardianEmail'] ?? null,
                'enrollment_date' => $row['enrollmentDate'],
                'status' => $row['status'],
                'notes' => $row['notes'] ?? null,
                'created_by' => $student->exists ? $student->created_by : $request->user()->id,
            ])->save();
        }
    }

    private function importEnrollments(School $school, array $rows, array &$summary): void
    {
        foreach ($rows as $row) {
            $studentId = Student::where('school_id', $school->id)
                ->where('admission_number', $row['admissionNumber'])
                ->value('id');
            $sessionId = $this->sessionId($school, $row['academicSessionName']);
            $classId = $this->classId($school, $row['className'], $row['classSection'] ?? null);

            if (! $studentId || ! $sessionId || ! $classId) {
                continue;
            }

            $enrollment = StudentEnrollment::firstOrNew([
                'student_id' => $studentId,
                'academic_session_id' => $sessionId,
            ]);
            $this->count($summary, 'enrollments', ! $enrollment->exists);

            $enrollment->fill([
                'school_id' => $school->id,
                'school_class_id' => $classId,
                'status' => $row['status'] ?? 'active',
                'enrolled_at' => $row['enrolledAt'] ?? now()->toDateString(),
            ])->save();

            Student::where('school_id', $school->id)
                ->where('id', $studentId)
                ->update(['school_class_id' => $classId]);
        }
    }

    private function importEmployees(Request $request, School $school, array $rows, array &$summary): void
    {
        foreach ($rows as $row) {
            $this->ensureEmployeeOption('school_employee_roles', $school, $row['role']);

            if (! empty($row['department'])) {
                $this->ensureEmployeeOption('school_employee_departments', $school, $row['department']);
            }

            $employee = Employee::firstOrNew([
                'school_id' => $school->id,
                'staff_id' => $row['staffId'],
            ]);
            $this->count($summary, 'employees', ! $employee->exists);
            $employee->fill([
                'first_name' => $row['firstName'],
                'last_name' => $row['lastName'],
                'email' => $row['email'] ?? null,
                'phone' => $row['phone'] ?? null,
                'role' => $row['role'],
                'department' => $row['department'] ?? null,
                'employment_type' => $row['employmentType'],
                'hire_date' => $row['hireDate'] ?? null,
                'salary' => $row['salary'] ?? null,
                'status' => $row['status'],
                'address' => $row['address'] ?? null,
                'notes' => $row['notes'] ?? null,
                'created_by' => $employee->exists ? $employee->created_by : $request->user()->id,
            ])->save();
        }
    }

    private function importFeeCategories(School $school, array $rows, array &$summary): void
    {
        foreach ($rows as $row) {
            $category = $school->feeCategories()->firstOrNew(['name' => $row['name']]);
            $this->count($summary, 'feeCategories', ! $category->exists);
            $category->fill([
                'amount' => $row['amount'],
                'billing_cycle' => $row['billingCycle'],
                'description' => $row['description'] ?? null,
                'is_active' => $row['isActive'] ?? true,
            ])->save();
        }
    }

    private function importFeeTemplates(School $school, array $rows, array &$summary): void
    {
        foreach ($rows as $row) {
            $template = $school->feeTemplates()->firstOrNew(['name' => $row['name']]);
            $this->count($summary, 'feeTemplates', ! $template->exists);
            $template->fill([
                'fee_category_id' => $this->feeCategoryId($school, $row['feeCategoryName'] ?? null),
                'description' => $row['description'] ?? null,
                'amount' => $row['amount'],
                'currency' => strtoupper($row['currency'] ?? 'NGN'),
                'is_recurring' => $row['isRecurring'] ?? false,
                'is_optional' => $row['isOptional'] ?? false,
                'status' => $row['status'] ?? 'active',
            ])->save();
        }
    }

    private function cleanRow(array $row, int $index): array
    {
        $cleaned = ['_row' => (int) ($row['_row'] ?? ($index + 2))];

        foreach ($row as $key => $value) {
            if ($key === '_row') {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
            }

            $cleaned[$key] = $value === '' ? null : $value;
        }

        return $cleaned;
    }

    private function hasImportValues(array $row): bool
    {
        foreach ($row as $key => $value) {
            if ($key !== '_row' && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function emptySummary(): array
    {
        return collect(self::SHEETS)
            ->mapWithKeys(fn ($sheet) => [$sheet => ['created' => 0, 'updated' => 0]])
            ->all();
    }

    private function count(array &$summary, string $sheet, bool $created): void
    {
        $summary[$sheet][$created ? 'created' : 'updated']++;
    }

    private function error(string $sheet, array $row, string $field, string $message): array
    {
        return [
            'sheet' => $sheet,
            'row' => $row['_row'] ?? null,
            'field' => $field,
            'message' => $message,
        ];
    }

    private function key(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function classKey(mixed $name, mixed $section): string
    {
        return $this->key($name).'|'.$this->key($section);
    }

    private function classId(School $school, ?string $name, ?string $section): ?int
    {
        if (! $name) {
            return null;
        }

        return SchoolClass::where('school_id', $school->id)
            ->where('name', $name)
            ->when($section, fn ($q) => $q->where('section', $section), fn ($q) => $q->whereNull('section'))
            ->value('id');
    }

    private function sessionId(School $school, ?string $name): ?int
    {
        if (! $name) {
            return null;
        }

        return AcademicSession::where('school_id', $school->id)->where('name', $name)->value('id');
    }

    private function feeCategoryId(School $school, ?string $name): ?int
    {
        if (! $name) {
            return null;
        }

        return FeeCategory::where('school_id', $school->id)->where('name', $name)->value('id');
    }

    private function subjectId(School $school, ?string $name): ?int
    {
        if (! $name) {
            return null;
        }

        return Subject::where('school_id', $school->id)->where('name', $name)->value('id');
    }

    private function ensureEmployeeOption(string $table, School $school, string $name): void
    {
        DB::table($table)->updateOrInsert(
            ['school_id' => $school->id, 'name' => trim($name)],
            ['updated_at' => now(), 'created_at' => now()],
        );
    }
}
