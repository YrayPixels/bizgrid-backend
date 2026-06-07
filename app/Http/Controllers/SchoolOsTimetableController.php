<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSchoolAccess;
use App\Models\School;
use App\Models\SchoolEvent;
use App\Models\TimetablePeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SchoolOsTimetableController extends Controller
{
    use AuthorizesSchoolAccess;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::STAFF_ROLES);

        $data = $request->validate([
            'academicSessionId' => [
                'required',
                'integer',
                Rule::exists('academic_sessions', 'id')->where('school_id', $school->id),
            ],
            'academicTermId' => [
                'required',
                'integer',
                Rule::exists('academic_terms', 'id')->where('school_id', $school->id),
            ],
            'schoolClassId' => [
                'nullable',
                'integer',
                Rule::exists('school_classes', 'id')->where('school_id', $school->id),
            ],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $periods = TimetablePeriod::query()
            ->where('school_id', $school->id)
            ->where('academic_session_id', $data['academicSessionId'])
            ->where('academic_term_id', $data['academicTermId'])
            ->when(! empty($data['schoolClassId']), fn ($query) => $query->where('school_class_id', $data['schoolClassId']))
            ->where('status', 'active')
            ->with(['schoolClass:id,name,section', 'subject:id,name,code', 'teacher:id,first_name,last_name,staff_id'])
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get()
            ->map(fn (TimetablePeriod $period) => $this->periodPayload($period));

        $from = ($data['from'] ?? now()->toDateString()).' 00:00:00';
        $to = ($data['to'] ?? now()->addMonths(3)->toDateString()).' 23:59:59';

        $holidays = SchoolEvent::query()
            ->where('school_id', $school->id)
            ->where('event_type', 'holiday')
            ->where('start_at', '<=', $to)
            ->where(function ($query) use ($from) {
                $query->whereNull('end_at')
                    ->where('start_at', '>=', $from)
                    ->orWhere('end_at', '>=', $from);
            })
            ->where(function ($periodQuery) use ($data) {
                $periodQuery->whereNull('academic_session_id')
                    ->orWhere('academic_session_id', $data['academicSessionId']);
            })
            ->where(function ($periodQuery) use ($data) {
                $periodQuery->whereNull('academic_term_id')
                    ->orWhere('academic_term_id', $data['academicTermId']);
            })
            ->orderBy('start_at')
            ->get()
            ->map(fn (SchoolEvent $event) => $this->holidayPayload($event));

        $events = SchoolEvent::query()
            ->where('school_id', $school->id)
            ->where('start_at', '<=', $to)
            ->where(function ($query) use ($from) {
                $query->whereNull('end_at')
                    ->where('start_at', '>=', $from)
                    ->orWhere('end_at', '>=', $from);
            })
            ->where(function ($periodQuery) use ($data) {
                $periodQuery->whereNull('academic_session_id')
                    ->orWhere('academic_session_id', $data['academicSessionId']);
            })
            ->where(function ($periodQuery) use ($data) {
                $periodQuery->whereNull('academic_term_id')
                    ->orWhere('academic_term_id', $data['academicTermId']);
            })
            ->orderBy('start_at')
            ->get()
            ->map(fn (SchoolEvent $event) => $this->eventPayload($event));

        return response()->json([
            'periods' => $periods,
            'holidays' => $holidays,
            'events' => $events,
        ]);
    }

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);

        $attributes = $this->validatedPeriodData($request, $school);
        $this->assertNoOverlap($school, $attributes);

        $period = $school->timetablePeriods()->create(array_merge(
            $attributes,
            ['created_by' => $request->user()->id],
        ));

        $period->load(['schoolClass:id,name,section', 'subject:id,name,code', 'teacher:id,first_name,last_name,staff_id']);

        return response()->json(['period' => $this->periodPayload($period)], 201);
    }

    public function update(Request $request, School $school, TimetablePeriod $period): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);
        $this->assertPeriodBelongsToSchool($school, $period);

        $attributes = $this->validatedPeriodData($request, $school, true);

        $startForCompare = $attributes['start_time'] ?? $this->formatTime($period->start_time);
        $endForCompare = $attributes['end_time'] ?? null;
        if ($endForCompare !== null && $endForCompare <= $startForCompare) {
            throw ValidationException::withMessages([
                'endTime' => ['End time must be after start time.'],
            ]);
        }

        $merged = array_merge([
            'academic_session_id' => $period->academic_session_id,
            'academic_term_id' => $period->academic_term_id,
            'school_class_id' => $period->school_class_id,
            'subject_id' => $period->subject_id,
            'teacher_employee_id' => $period->teacher_employee_id,
            'weekday' => $period->weekday,
            'start_time' => $period->start_time,
            'end_time' => $period->end_time,
        ], $attributes);
        $this->assertNoOverlap($school, $merged, $period->id);

        $period->update($attributes);
        $period->load(['schoolClass:id,name,section', 'subject:id,name,code', 'teacher:id,first_name,last_name,staff_id']);

        return response()->json(['period' => $this->periodPayload($period->fresh())]);
    }

    public function destroy(Request $request, School $school, TimetablePeriod $period): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);
        $this->assertPeriodBelongsToSchool($school, $period);

        $period->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPeriodData(Request $request, School $school, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'academicSessionId' => [
                $required,
                'integer',
                Rule::exists('academic_sessions', 'id')->where('school_id', $school->id),
            ],
            'academicTermId' => [
                $required,
                'integer',
                Rule::exists('academic_terms', 'id')->where('school_id', $school->id),
            ],
            'schoolClassId' => array_filter([
                $partial ? 'sometimes' : 'required',
                'integer',
                Rule::exists('school_classes', 'id')->where('school_id', $school->id),
            ]),
            'subjectId' => array_filter([
                $partial ? 'sometimes' : 'required',
                'integer',
                Rule::exists('subjects', 'id')->where('school_id', $school->id),
            ]),
            'teacherEmployeeId' => array_filter([
                $partial ? 'sometimes' : 'required',
                'integer',
                Rule::exists('employees', 'id')->where('school_id', $school->id),
            ]),
            'weekday' => [$required, Rule::in(TimetablePeriod::WEEKDAYS)],
            'startTime' => [$required, 'date_format:H:i'],
            'endTime' => array_filter([
                $partial ? 'sometimes' : 'required',
                'date_format:H:i',
                $partial ? null : 'after:startTime',
            ]),
            'room' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $attributes = [
            'school_id' => $school->id,
        ];

        foreach ([
            'academicSessionId' => 'academic_session_id',
            'academicTermId' => 'academic_term_id',
            'schoolClassId' => 'school_class_id',
            'subjectId' => 'subject_id',
            'teacherEmployeeId' => 'teacher_employee_id',
            'weekday' => 'weekday',
            'startTime' => 'start_time',
            'endTime' => 'end_time',
            'room' => 'room',
            'notes' => 'notes',
            'status' => 'status',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        if (! array_key_exists('status', $attributes) && ! $partial) {
            $attributes['status'] = 'active';
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertNoOverlap(School $school, array $attributes, ?int $ignoreId = null): void
    {
        $requiredKeys = [
            'academic_session_id',
            'academic_term_id',
            'weekday',
            'start_time',
            'end_time',
            'teacher_employee_id',
            'school_class_id',
        ];

        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $attributes)) {
                return;
            }
        }

        $conflicts = TimetablePeriod::query()
            ->where('school_id', $school->id)
            ->where('academic_session_id', $attributes['academic_session_id'])
            ->where('academic_term_id', $attributes['academic_term_id'])
            ->where('weekday', $attributes['weekday'])
            ->where('status', 'active')
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where(function ($query) use ($attributes) {
                $query->where('teacher_employee_id', $attributes['teacher_employee_id'])
                    ->orWhere('school_class_id', $attributes['school_class_id']);
            })
            ->where('start_time', '<', $attributes['end_time'])
            ->where('end_time', '>', $attributes['start_time'])
            ->with(['schoolClass:id,name,section', 'teacher:id,first_name,last_name'])
            ->first();

        if (! $conflicts) {
            return;
        }

        $teacherConflict = (int) $conflicts->teacher_employee_id === (int) $attributes['teacher_employee_id'];
        $classConflict = (int) $conflicts->school_class_id === (int) $attributes['school_class_id'];

        $message = $teacherConflict && $classConflict
            ? 'This time slot conflicts with an existing lesson for the same teacher and class.'
            : ($teacherConflict
                ? 'This teacher already has a lesson scheduled at this time.'
                : 'This class already has a lesson scheduled at this time.');

        throw ValidationException::withMessages([
            'startTime' => [$message],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function periodPayload(TimetablePeriod $period): array
    {
        $class = $period->schoolClass;
        $teacher = $period->teacher;
        $subject = $period->subject;

        return [
            'id' => (string) $period->id,
            'school_id' => (string) $period->school_id,
            'academic_session_id' => (int) $period->academic_session_id,
            'academic_term_id' => (int) $period->academic_term_id,
            'school_class_id' => (int) $period->school_class_id,
            'subject_id' => (int) $period->subject_id,
            'teacher_employee_id' => (int) $period->teacher_employee_id,
            'weekday' => $period->weekday,
            'start_time' => $this->formatTime($period->start_time),
            'end_time' => $this->formatTime($period->end_time),
            'room' => $period->room,
            'notes' => $period->notes,
            'status' => $period->status,
            'school_class' => $class ? [
                'id' => (int) $class->id,
                'name' => $class->name,
                'section' => $class->section,
            ] : null,
            'subject' => $subject ? [
                'id' => (int) $subject->id,
                'name' => $subject->name,
                'code' => $subject->code,
            ] : null,
            'teacher' => $teacher ? [
                'id' => (int) $teacher->id,
                'first_name' => $teacher->first_name,
                'last_name' => $teacher->last_name,
                'staff_id' => $teacher->staff_id,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function holidayPayload(SchoolEvent $event): array
    {
        return [
            'id' => (string) $event->id,
            'title' => $event->title,
            'event_type' => $event->event_type,
            'start_at' => $event->start_at?->toIso8601String(),
            'end_at' => $event->end_at?->toIso8601String(),
            'all_day' => (bool) $event->all_day,
            'description' => $event->description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(SchoolEvent $event): array
    {
        return [
            'id' => (string) $event->id,
            'school_id' => (string) $event->school_id,
            'academic_session_id' => $event->academic_session_id ? (int) $event->academic_session_id : null,
            'academic_term_id' => $event->academic_term_id ? (int) $event->academic_term_id : null,
            'title' => $event->title,
            'event_type' => $event->event_type,
            'start_at' => $event->start_at?->toIso8601String(),
            'end_at' => $event->end_at?->toIso8601String(),
            'all_day' => (bool) $event->all_day,
            'location' => $event->location,
            'audience' => $event->audience,
            'description' => $event->description,
        ];
    }

    private function formatTime(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        if (is_string($value) && strlen($value) >= 5) {
            return substr($value, 0, 5);
        }

        return (string) $value;
    }

    private function assertPeriodBelongsToSchool(School $school, TimetablePeriod $period): void
    {
        abort_unless((int) $period->school_id === (int) $school->id, 404);
    }
}
