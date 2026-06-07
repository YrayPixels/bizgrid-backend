<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSchoolAccess;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SchoolOsAcademicsController extends Controller
{
    use AuthorizesSchoolAccess;

    public function sessions(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::STAFF_ROLES);

        $sessions = $school->academicSessions()
            ->withCount('enrollments')
            ->orderByDesc('start_date')
            ->get();

        return response()->json(['sessions' => $sessions]);
    }

    public function storeSession(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:40',
                Rule::unique('academic_sessions', 'name')->where('school_id', $school->id),
            ],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'status' => ['required', Rule::in(['planned', 'active', 'archived'])],
        ]);

        $session = DB::transaction(function () use ($school, $data) {
            if ($data['status'] === 'active') {
                $school->academicSessions()->where('status', 'active')->update(['status' => 'archived']);
            }

            $session = $school->academicSessions()->create([
                'name' => $data['name'],
                'start_date' => $data['startDate'],
                'end_date' => $data['endDate'],
                'status' => $data['status'],
            ]);

            $session->terms()->createMany([
                [
                    'school_id' => $school->id,
                    'name' => 'First Term',
                    'start_date' => null,
                    'end_date' => null,
                    'is_current' => false,
                ],
                [
                    'school_id' => $school->id,
                    'name' => 'Second Term',
                    'start_date' => null,
                    'end_date' => null,
                    'is_current' => false,
                ],
                [
                    'school_id' => $school->id,
                    'name' => 'Third Term',
                    'start_date' => null,
                    'end_date' => null,
                    'is_current' => false,
                ],
            ]);

            return $session;
        });

        return response()->json(['session' => $session], 201);
    }

    public function updateSession(Request $request, School $school, AcademicSession $session): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);
        $this->assertBelongsToSchool($school, $session);

        $data = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:40',
                Rule::unique('academic_sessions', 'name')->where('school_id', $school->id)->ignore($session->id),
            ],
            'startDate' => ['sometimes', 'date'],
            'endDate' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::in(['planned', 'active', 'archived'])],
        ]);

        DB::transaction(function () use ($school, $session, $data) {
            if (($data['status'] ?? null) === 'active') {
                $school->academicSessions()->where('id', '!=', $session->id)->where('status', 'active')->update(['status' => 'archived']);
            }

            $session->update([
                'name' => $data['name'] ?? $session->name,
                'start_date' => $data['startDate'] ?? $session->start_date,
                'end_date' => $data['endDate'] ?? $session->end_date,
                'status' => $data['status'] ?? $session->status,
            ]);
        });

        return response()->json(['session' => $session->fresh()]);
    }

    public function classes(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::STAFF_ROLES);

        $classes = $school->schoolClasses()
            ->withCount('students')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (SchoolClass $c) => $this->classPayload($c));

        return response()->json(['classes' => $classes]);
    }

    public function storeClass(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'section' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sortOrder' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $class = $school->schoolClasses()->create([
            'name' => $data['name'],
            'section' => $data['section'] ?? null,
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sortOrder'] ?? 0,
            'is_active' => $data['isActive'] ?? true,
        ]);

        return response()->json(['class' => $this->classPayload($class)], 201);
    }

    public function updateClass(Request $request, School $school, SchoolClass $schoolClass): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);
        $this->assertBelongsToSchool($school, $schoolClass);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'section' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sortOrder' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $schoolClass->update([
            'name' => $data['name'] ?? $schoolClass->name,
            'section' => array_key_exists('section', $data) ? $data['section'] : $schoolClass->section,
            'description' => array_key_exists('description', $data) ? $data['description'] : $schoolClass->description,
            'sort_order' => $data['sortOrder'] ?? $schoolClass->sort_order,
            'is_active' => $data['isActive'] ?? $schoolClass->is_active,
        ]);

        return response()->json(['class' => $this->classPayload($schoolClass->fresh()->loadCount('students'))]);
    }

    public function deleteClass(Request $request, School $school, SchoolClass $schoolClass): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);
        $this->assertBelongsToSchool($school, $schoolClass);

        $schoolClass->delete();

        return response()->json(['ok' => true]);
    }

    public function subjects(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::STAFF_ROLES);

        $subjects = $school->subjects()->orderBy('name')->get();

        return response()->json(['subjects' => $subjects]);
    }

    public function storeSubject(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('subjects', 'name')->where('school_id', $school->id),
            ],
            'code' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $subject = $school->subjects()->create($data);

        return response()->json(['subject' => $subject], 201);
    }

    public function updateSubject(Request $request, School $school, Subject $subject): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);
        $this->assertBelongsToSchool($school, $subject);

        $data = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:80',
                Rule::unique('subjects', 'name')->where('school_id', $school->id)->ignore($subject->id),
            ],
            'code' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $subject->update($data);

        return response()->json(['subject' => $subject->fresh()]);
    }

    public function deleteSubject(Request $request, School $school, Subject $subject): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);
        $this->assertBelongsToSchool($school, $subject);

        $subject->delete();

        return response()->json(['ok' => true]);
    }

    public function terms(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::STAFF_ROLES);

        $terms = $school->academicTerms()
            ->with('academicSession:id,name,status')
            ->orderByDesc('start_date')
            ->get();

        return response()->json(['terms' => $terms]);
    }

    public function storeTerm(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'academicSessionId' => [
                'nullable',
                'integer',
                Rule::exists('academic_sessions', 'id')->where('school_id', $school->id),
            ],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', Rule::when($request->filled('startDate'), ['after_or_equal:startDate'])],
            'isCurrent' => ['nullable', 'boolean'],
        ]);

        $term = DB::transaction(function () use ($school, $data) {
            if (! empty($data['isCurrent'])) {
                $school->academicTerms()->update(['is_current' => false]);
            }

            return $school->academicTerms()->create([
                'name' => $data['name'],
                'academic_session_id' => $data['academicSessionId'] ?? null,
                'start_date' => $data['startDate'] ?? null,
                'end_date' => $data['endDate'] ?? null,
                'is_current' => $data['isCurrent'] ?? false,
            ]);
        });

        return response()->json(['term' => $term], 201);
    }

    public function updateTerm(Request $request, School $school, AcademicTerm $term): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);
        $this->assertBelongsToSchool($school, $term);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'academicSessionId' => [
                'nullable',
                'integer',
                Rule::exists('academic_sessions', 'id')->where('school_id', $school->id),
            ],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', Rule::when($request->filled('startDate'), ['after_or_equal:startDate'])],
            'isCurrent' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($school, $term, $data) {
            if (! empty($data['isCurrent'])) {
                $school->academicTerms()->where('id', '!=', $term->id)->update(['is_current' => false]);
            }

            $term->update([
                'name' => $data['name'] ?? $term->name,
                'academic_session_id' => array_key_exists('academicSessionId', $data) ? $data['academicSessionId'] : $term->academic_session_id,
                'start_date' => array_key_exists('startDate', $data) ? $data['startDate'] : $term->start_date,
                'end_date' => array_key_exists('endDate', $data) ? $data['endDate'] : $term->end_date,
                'is_current' => $data['isCurrent'] ?? $term->is_current,
            ]);
        });

        return response()->json(['term' => $term->fresh()]);
    }

    public function deleteTerm(Request $request, School $school, AcademicTerm $term): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);
        $this->assertBelongsToSchool($school, $term);

        $term->delete();

        return response()->json(['ok' => true]);
    }

    public function classSubjects(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::STAFF_ROLES);

        $rows = DB::table('class_subject')
            ->where('school_id', $school->id)
            ->join('school_classes', 'school_classes.id', '=', 'class_subject.school_class_id')
            ->join('subjects', 'subjects.id', '=', 'class_subject.subject_id')
            ->select([
                'class_subject.id',
                'class_subject.school_class_id',
                'class_subject.subject_id',
                'school_classes.name as class_name',
                'subjects.name as subject_name',
            ])
            ->orderBy('school_classes.name')
            ->get();

        return response()->json(['assignments' => $rows]);
    }

    public function assignClassSubject(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);

        $data = $request->validate([
            'schoolClassId' => [
                'required',
                'integer',
                Rule::exists('school_classes', 'id')->where('school_id', $school->id),
            ],
            'subjectId' => [
                'required',
                'integer',
                Rule::exists('subjects', 'id')->where('school_id', $school->id),
            ],
        ]);

        $exists = DB::table('class_subject')
            ->where('school_class_id', $data['schoolClassId'])
            ->where('subject_id', $data['subjectId'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Subject already assigned to this class.'], 422);
        }

        $id = DB::table('class_subject')->insertGetId([
            'school_id' => $school->id,
            'school_class_id' => $data['schoolClassId'],
            'subject_id' => $data['subjectId'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['assignment' => ['id' => $id, ...$data]], 201);
    }

    public function unassignClassSubject(Request $request, School $school, int $assignment): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);

        $deleted = DB::table('class_subject')
            ->where('school_id', $school->id)
            ->where('id', $assignment)
            ->delete();

        abort_unless($deleted, 404);

        return response()->json(['ok' => true]);
    }

    public function enrollments(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::STAFF_ROLES);

        $data = $request->validate([
            'academicSessionId' => [
                'nullable',
                'integer',
                Rule::exists('academic_sessions', 'id')->where('school_id', $school->id),
            ],
            'schoolClassId' => [
                'nullable',
                'integer',
                Rule::exists('school_classes', 'id')->where('school_id', $school->id),
            ],
        ]);

        $query = StudentEnrollment::query()
            ->where('school_id', $school->id)
            ->with([
                'student:id,admission_number,first_name,last_name,status',
                'academicSession:id,name,status',
                'schoolClass:id,name,section',
            ])
            ->orderByDesc('created_at')
            ->limit(300);

        if (! empty($data['academicSessionId'])) {
            $query->where('academic_session_id', $data['academicSessionId']);
        }

        if (! empty($data['schoolClassId'])) {
            $query->where('school_class_id', $data['schoolClassId']);
        }

        return response()->json(['enrollments' => $query->get()]);
    }

    public function enrollStudent(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);

        $data = $request->validate([
            'studentId' => [
                'required',
                'integer',
                Rule::exists('students', 'id')->where('school_id', $school->id),
            ],
            'academicSessionId' => [
                'required',
                'integer',
                Rule::exists('academic_sessions', 'id')->where('school_id', $school->id),
            ],
            'schoolClassId' => [
                'required',
                'integer',
                Rule::exists('school_classes', 'id')->where('school_id', $school->id),
            ],
            'status' => ['nullable', Rule::in(['active', 'promoted', 'transferred', 'graduated', 'withdrawn'])],
            'enrolledAt' => ['nullable', 'date'],
        ]);

        $enrollment = DB::transaction(function () use ($school, $data) {
            $enrollment = StudentEnrollment::updateOrCreate(
                [
                    'student_id' => $data['studentId'],
                    'academic_session_id' => $data['academicSessionId'],
                ],
                [
                    'school_id' => $school->id,
                    'school_class_id' => $data['schoolClassId'],
                    'status' => $data['status'] ?? 'active',
                    'enrolled_at' => $data['enrolledAt'] ?? now()->toDateString(),
                ],
            );

            Student::where('school_id', $school->id)
                ->where('id', $data['studentId'])
                ->update(['school_class_id' => $data['schoolClassId']]);

            return $enrollment;
        });

        return response()->json([
            'enrollment' => $enrollment->load(['student', 'academicSession', 'schoolClass']),
        ], 201);
    }

    private function classPayload(SchoolClass $class): array
    {
        return [
            'id' => (string) $class->id,
            'name' => $class->name,
            'section' => $class->section,
            'description' => $class->description,
            'sort_order' => $class->sort_order,
            'is_active' => $class->is_active,
            'student_count' => $class->students_count ?? $class->students()->count(),
        ];
    }

    private function assertBelongsToSchool(School $school, SchoolClass|Subject|AcademicTerm|AcademicSession $model): void
    {
        abort_unless((int) $model->school_id === (int) $school->id, 404);
    }
}
