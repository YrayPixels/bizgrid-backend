<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSchoolAccess;
use App\Models\Employee;
use App\Models\School;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SchoolOsSchoolController extends Controller
{
    use AuthorizesSchoolAccess;
    private const RESERVED_SLUGS = [
        'www',
        'app',
        'api',
        'admin',
        'dashboard',
        'portal',
        'docs',
        'help',
        'status',
        'blog',
        'mail',
        'static',
        'assets',
        'cdn',
        'schoolos',
        'support',
        'auth',
        'login',
        'signup',
        'onboarding',
    ];

    public function checkSlug(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])?$/'],
        ]);

        $slug = strtolower($data['slug']);

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return response()->json(['available' => false, 'reason' => 'reserved']);
        }

        return response()->json([
            'available' => ! School::where('slug', $slug)->exists(),
            'reason' => School::where('slug', $slug)->exists() ? 'taken' : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])?$/', Rule::unique('schools', 'slug')],
            'motto' => ['nullable', 'string', 'max:200'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
        ]);

        $slug = strtolower($data['slug']);

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return response()->json(['ok' => false, 'error' => 'That subdomain is reserved.'], 422);
        }

        $school = DB::transaction(function () use ($request, $data, $slug) {
            $school = School::create([
                'name' => $data['name'],
                'slug' => $slug,
                'motto' => $data['motto'] ?? null,
                'contact_email' => $data['contactEmail'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $school->users()->attach($request->user()->id, ['role' => 'owner']);

            return $school;
        });

        return response()->json([
            'ok' => true,
            'tenant' => $this->schoolPayload($school, 'owner'),
        ], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $schools = $request->user()
            ->belongsToMany(School::class, 'school_user')
            ->withPivot('role')
            ->orderBy('schools.name')
            ->get()
            ->map(fn (School $school) => $this->schoolPayload($school, $school->pivot->role));

        return response()->json(['tenants' => $schools]);
    }

    public function bySlug(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'min:3', 'max:32'],
        ]);

        $school = School::where('slug', strtolower($data['slug']))->first();

        if (! $school) {
            return response()->json(['tenant' => null, 'role' => null]);
        }

        $membership = DB::table('school_user')
            ->where('school_id', $school->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $membership) {
            return response()->json(['tenant' => null, 'role' => null]);
        }

        return response()->json([
            'tenant' => $this->schoolPayload($school),
            'role' => $membership->role,
        ]);
    }

    public function students(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school);

        $query = $school->students()->orderBy('last_name')->limit(500);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('admission_number', 'like', "%{$search}%")
                    ->orWhere('guardian_name', 'like', "%{$search}%");
            });
        }

        return response()->json(['students' => $query->get()]);
    }

    public function storeStudent(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school);

        $data = $this->studentData($request, $school, null, false);
        $student = DB::transaction(function () use ($request, $school, $data) {
            if (empty($data['admissionNumber'])) {
                $data['admissionNumber'] = $this->generateAdmissionNumber($school, $data['enrollmentDate']);
            }

            return $school->students()->create([
                ...$this->studentPayload($data),
                'created_by' => $request->user()->id,
            ]);
        });

        return response()->json(['student' => $student], 201);
    }

    public function updateStudent(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school);

        $data = $request->validate([
            'id' => ['required', 'integer', Rule::exists('students', 'id')->where('school_id', $school->id)],
        ]);

        $student = $school->students()->findOrFail($data['id']);
        $student->update($this->studentPayload($this->studentData($request, $school, $student->id)));

        return response()->json(['student' => $student->fresh()]);
    }

    public function deleteStudent(Request $request, School $school, Student $student): JsonResponse
    {
        $this->authorizeSchool($request, $school);

        if ($student->school_id !== $school->id) {
            abort(404);
        }

        $student->delete();

        return response()->json(['ok' => true]);
    }

    public function employees(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school);

        $query = $school->employees()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(500);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('staff_id', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('role', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%");
            });
        }

        return response()->json(['employees' => $query->get()]);
    }

    public function employeeRoles(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school);

        return response()->json([
            'roles' => $this->employeeOptions('school_employee_roles', $school),
        ]);
    }

    public function storeEmployeeRole(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        return response()->json([
            'role' => $this->ensureEmployeeOption('school_employee_roles', $school, $data['name']),
        ], 201);
    }

    public function employeeDepartments(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school);

        return response()->json([
            'departments' => $this->employeeOptions('school_employee_departments', $school),
        ]);
    }

    public function storeEmployeeDepartment(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        return response()->json([
            'department' => $this->ensureEmployeeOption('school_employee_departments', $school, $data['name']),
        ], 201);
    }

    public function storeEmployee(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school);

        $data = $this->employeeData($request, $school);
        $employee = DB::transaction(function () use ($request, $school, $data) {
            if (empty($data['staffId'])) {
                $data['staffId'] = $this->generateStaffId($school);
            }

            $this->registerEmployeeOptions($school, $data);

            return $school->employees()->create([
                ...$this->employeePayload($data),
                'created_by' => $request->user()->id,
            ]);
        });

        return response()->json(['employee' => $employee], 201);
    }

    public function updateEmployee(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school);

        $data = $request->validate([
            'id' => ['required', 'integer', Rule::exists('employees', 'id')->where('school_id', $school->id)],
        ]);

        $employee = $school->employees()->findOrFail($data['id']);
        $employeeData = $this->employeeData($request, $school, $employee->id);
        $this->registerEmployeeOptions($school, $employeeData);
        $employee->update($this->employeePayload($employeeData));

        return response()->json(['employee' => $employee->fresh()]);
    }

    public function deleteEmployee(Request $request, School $school, Employee $employee): JsonResponse
    {
        $this->authorizeSchool($request, $school);

        if ($employee->school_id !== $school->id) {
            abort(404);
        }

        $employee->delete();

        return response()->json(['ok' => true]);
    }

    private function studentData(
        Request $request,
        School $school,
        ?int $ignoreId = null,
        bool $requireAdmissionNumber = true,
    ): array
    {
        if (! $requireAdmissionNumber && trim((string) $request->input('admissionNumber', '')) === '') {
            $request->merge(['admissionNumber' => null]);
        }

        return $request->validate([
            'admissionNumber' => [
                $requireAdmissionNumber ? 'required' : 'nullable',
                'string',
                'max:40',
                'regex:/^[A-Za-z0-9\\-_/]+$/',
                Rule::unique('students', 'admission_number')
                    ->where('school_id', $school->id)
                    ->ignore($ignoreId),
            ],
            'firstName' => ['required', 'string', 'max:80'],
            'lastName' => ['required', 'string', 'max:80'],
            'dateOfBirth' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'classLevel' => ['nullable', 'string', 'max:40'],
            'schoolClassId' => [
                'nullable',
                'integer',
                Rule::exists('school_classes', 'id')->where('school_id', $school->id),
            ],
            'guardianName' => ['nullable', 'string', 'max:120'],
            'guardianPhone' => ['nullable', 'string', 'max:40'],
            'guardianEmail' => ['nullable', 'email', 'max:255'],
            'enrollmentDate' => ['required', 'date'],
            'status' => ['required', Rule::in(['enrolled', 'withdrawn', 'graduated', 'suspended'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function generateAdmissionNumber(School $school, string $enrollmentDate): string
    {
        $prefix = $this->schoolInitials($school);
        $year = date('Y', strtotime($enrollmentDate));
        $admissionNumbers = $school->students()
            ->where('admission_number', 'like', "{$prefix}/{$year}/%")
            ->lockForUpdate()
            ->pluck('admission_number');

        $nextNumber = 1;
        foreach ($admissionNumbers as $admissionNumber) {
            if (
                preg_match(
                    '/^' . preg_quote("{$prefix}/{$year}/", '/') . '(\d+)$/',
                    $admissionNumber,
                    $matches
                )
            ) {
                $nextNumber = max($nextNumber, ((int) $matches[1]) + 1);
            }
        }

        do {
            $candidate = sprintf('%s/%s/%04d', $prefix, $year, $nextNumber++);
        } while ($school->students()->where('admission_number', $candidate)->exists());

        return $candidate;
    }

    private function generateStaffId(School $school): string
    {
        $prefix = $this->schoolInitials($school);
        $staffIds = $school->employees()
            ->where('staff_id', 'like', "{$prefix}-%")
            ->lockForUpdate()
            ->pluck('staff_id');

        $nextNumber = 1;
        foreach ($staffIds as $staffId) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', $staffId, $matches)) {
                $nextNumber = max($nextNumber, ((int) $matches[1]) + 1);
            }
        }

        do {
            $candidate = sprintf('%s-%03d', $prefix, $nextNumber++);
        } while ($school->employees()->where('staff_id', $candidate)->exists());

        return $candidate;
    }

    private function schoolInitials(School $school): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $school->name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) === 1) {
            $prefix = substr(preg_replace('/[^A-Za-z0-9]/', '', $words[0]) ?? '', 0, 3);
        } else {
            $prefix = '';
            foreach ($words as $word) {
                $prefix .= substr($word, 0, 1);
            }
            $prefix = substr($prefix, 0, 6);
        }

        if ($prefix === '') {
            $prefix = substr(preg_replace('/[^A-Za-z0-9]/', '', $school->slug) ?? '', 0, 3);
        }

        return strtoupper($prefix ?: 'SCH');
    }

    private function registerEmployeeOptions(School $school, array $data): void
    {
        $this->ensureEmployeeOption('school_employee_roles', $school, $data['role']);

        if (! empty($data['department'])) {
            $this->ensureEmployeeOption('school_employee_departments', $school, $data['department']);
        }
    }

    private function employeeOptions(string $table, School $school)
    {
        return DB::table($table)
            ->where('school_id', $school->id)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function ensureEmployeeOption(string $table, School $school, string $name)
    {
        $name = trim($name);
        if ($name === '') {
            abort(422, 'Option name is required.');
        }

        $now = now();

        DB::table($table)->updateOrInsert(
            ['school_id' => $school->id, 'name' => $name],
            ['updated_at' => $now, 'created_at' => $now],
        );

        return DB::table($table)
            ->where('school_id', $school->id)
            ->where('name', $name)
            ->first(['id', 'name']);
    }

    private function employeeData(Request $request, School $school, ?int $ignoreId = null): array
    {
        return $request->validate([
            'staffId' => [
                $ignoreId ? 'required' : 'nullable',
                'string',
                'max:40',
                'regex:/^[A-Za-z0-9\\-_/]+$/',
                Rule::unique('employees', 'staff_id')
                    ->where('school_id', $school->id)
                    ->ignore($ignoreId),
            ],
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
        ]);
    }

    private function schoolPayload(School $school, ?string $role = null): array
    {
        return [
            'id' => (string) $school->id,
            'slug' => $school->slug,
            'name' => $school->name,
            'logo_url' => null,
            'status' => $school->status ?? 'active',
            'role' => $role,
        ];
    }

    private function studentPayload(array $data): array
    {
        return [
            'admission_number' => $data['admissionNumber'],
            'first_name' => $data['firstName'],
            'last_name' => $data['lastName'],
            'date_of_birth' => $data['dateOfBirth'] ?? null,
            'gender' => $data['gender'] ?? null,
            'class_level' => $data['classLevel'] ?? null,
            'school_class_id' => $data['schoolClassId'] ?? null,
            'guardian_name' => $data['guardianName'] ?? null,
            'guardian_phone' => $data['guardianPhone'] ?? null,
            'guardian_email' => $data['guardianEmail'] ?? null,
            'enrollment_date' => $data['enrollmentDate'],
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function employeePayload(array $data): array
    {
        return [
            'staff_id' => $data['staffId'],
            'first_name' => $data['firstName'],
            'last_name' => $data['lastName'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'department' => $data['department'] ?? null,
            'employment_type' => $data['employmentType'],
            'hire_date' => $data['hireDate'] ?? null,
            'salary' => $data['salary'] ?? null,
            'status' => $data['status'],
            'address' => $data['address'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }
}
