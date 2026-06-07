<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSchoolAccess;
use App\Models\AttendanceRecord;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SchoolOsAttendanceController extends Controller
{
    use AuthorizesSchoolAccess;

    public function summary(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::STAFF_ROLES);

        $date = $request->query('date', now()->toDateString());

        $counts = AttendanceRecord::query()
            ->where('school_id', $school->id)
            ->whereDate('attendance_date', $date)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $byClass = AttendanceRecord::query()
            ->where('attendance_records.school_id', $school->id)
            ->whereDate('attendance_date', $date)
            ->join('school_classes', 'school_classes.id', '=', 'attendance_records.school_class_id')
            ->select('school_classes.id', 'school_classes.name', 'school_classes.section', DB::raw('count(*) as marked'))
            ->groupBy('school_classes.id', 'school_classes.name', 'school_classes.section')
            ->get();

        return response()->json([
            'date' => $date,
            'present' => (int) ($counts['present'] ?? 0),
            'absent' => (int) ($counts['absent'] ?? 0),
            'late' => (int) ($counts['late'] ?? 0),
            'excused' => (int) ($counts['excused'] ?? 0),
            'by_class' => $byClass,
        ]);
    }

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::STAFF_ROLES);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'schoolClassId' => [
                'nullable',
                'integer',
                Rule::exists('school_classes', 'id')->where('school_id', $school->id),
            ],
        ]);

        $studentsQuery = $school->students()
            ->where('status', 'enrolled')
            ->orderBy('last_name');

        if (! empty($data['schoolClassId'])) {
            $studentsQuery->where('school_class_id', $data['schoolClassId']);
        }

        $students = $studentsQuery->get();

        $records = AttendanceRecord::query()
            ->where('school_id', $school->id)
            ->whereDate('attendance_date', $data['date'])
            ->when(! empty($data['schoolClassId']), fn ($q) => $q->where('school_class_id', $data['schoolClassId']))
            ->get()
            ->keyBy('student_id');

        $rows = $students->map(function (Student $student) use ($records, $data) {
            $record = $records->get($student->id);

            return [
                'student_id' => (string) $student->id,
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'admission_number' => $student->admission_number,
                'school_class_id' => $student->school_class_id ? (string) $student->school_class_id : null,
                'status' => $record?->status,
                'notes' => $record?->notes,
                'record_id' => $record ? (string) $record->id : null,
            ];
        });

        return response()->json([
            'date' => $data['date'],
            'school_class_id' => $data['schoolClassId'] ?? null,
            'rows' => $rows,
        ]);
    }

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::STAFF_ROLES);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'schoolClassId' => [
                'nullable',
                'integer',
                Rule::exists('school_classes', 'id')->where('school_id', $school->id),
            ],
            'records' => ['required', 'array', 'min:1'],
            'records.*.studentId' => [
                'required',
                'integer',
                Rule::exists('students', 'id')->where('school_id', $school->id),
            ],
            'records.*.status' => ['required', Rule::in(['present', 'absent', 'late', 'excused'])],
            'records.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $classId = $data['schoolClassId'] ?? null;

        DB::transaction(function () use ($request, $school, $data, $classId) {
            foreach ($data['records'] as $row) {
                $student = Student::where('school_id', $school->id)->findOrFail($row['studentId']);

                AttendanceRecord::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'attendance_date' => $data['date'],
                    ],
                    [
                        'school_id' => $school->id,
                        'school_class_id' => $classId ?? $student->school_class_id,
                        'status' => $row['status'],
                        'notes' => $row['notes'] ?? null,
                        'marked_by' => $request->user()->id,
                    ],
                );
            }
        });

        return response()->json(['ok' => true]);
    }

    public function classesWithCounts(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::STAFF_ROLES);

        $date = $request->query('date', now()->toDateString());

        $classes = $school->schoolClasses()
            ->where('is_active', true)
            ->withCount([
                'students as enrolled_count' => fn ($q) => $q->where('status', 'enrolled'),
            ])
            ->orderBy('name')
            ->get()
            ->map(function (SchoolClass $class) use ($school, $date) {
                $marked = AttendanceRecord::query()
                    ->where('school_id', $school->id)
                    ->where('school_class_id', $class->id)
                    ->whereDate('attendance_date', $date)
                    ->count();

                return [
                    'id' => (string) $class->id,
                    'name' => $class->name,
                    'section' => $class->section,
                    'enrolled_count' => $class->enrolled_count,
                    'marked_count' => $marked,
                ];
            });

        return response()->json(['date' => $date, 'classes' => $classes]);
    }
}
