<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSchoolAccess;
use App\Models\School;
use App\Models\SchoolEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SchoolOsCalendarController extends Controller
{
    use AuthorizesSchoolAccess;

    private const EVENT_TYPES = [
        'event',
        'exam',
        'interhouse_sports',
        'holiday',
        'midterm_break',
        'sports',
        'meeting',
        'deadline',
        'other',
    ];

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::STAFF_ROLES);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'academicSessionId' => [
                'nullable',
                'integer',
                Rule::exists('academic_sessions', 'id')->where('school_id', $school->id),
            ],
            'academicTermId' => [
                'nullable',
                'integer',
                Rule::exists('academic_terms', 'id')->where('school_id', $school->id),
            ],
            'type' => ['nullable', Rule::in(self::EVENT_TYPES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $from = ($data['from'] ?? now()->toDateString()).' 00:00:00';
        $to = ($data['to'] ?? now()->addDays(60)->toDateString()).' 23:59:59';

        $events = SchoolEvent::query()
            ->where('school_id', $school->id)
            ->where('start_at', '<=', $to)
            ->where(function ($query) use ($from) {
                $query->whereNull('end_at')
                    ->where('start_at', '>=', $from)
                    ->orWhere('end_at', '>=', $from);
            })
            ->when(! empty($data['academicSessionId']), function ($query) use ($data) {
                $query->where(function ($periodQuery) use ($data) {
                    $periodQuery->whereNull('academic_session_id')
                        ->orWhere('academic_session_id', $data['academicSessionId']);
                });
            })
            ->when(! empty($data['academicTermId']), function ($query) use ($data) {
                $query->where(function ($periodQuery) use ($data) {
                    $periodQuery->whereNull('academic_term_id')
                        ->orWhere('academic_term_id', $data['academicTermId']);
                });
            })
            ->when(! empty($data['type']), fn ($query) => $query->where('event_type', $data['type']))
            ->orderBy('start_at')
            ->limit($data['limit'] ?? 20)
            ->get()
            ->map(fn (SchoolEvent $event) => $this->eventPayload($event));

        return response()->json(['events' => $events]);
    }

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);

        $event = $school->events()->create(array_merge(
            $this->validatedEventData($request, $school),
            ['created_by' => $request->user()->id],
        ));

        return response()->json(['event' => $this->eventPayload($event)], 201);
    }

    public function update(Request $request, School $school, SchoolEvent $event): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);
        $this->assertEventBelongsToSchool($school, $event);

        $event->update($this->validatedEventData($request, $school, true));

        return response()->json(['event' => $this->eventPayload($event->fresh())]);
    }

    public function destroy(Request $request, School $school, SchoolEvent $event): JsonResponse
    {
        $this->authorizeSchool($request, $school, self::ADMIN_ROLES);
        $this->assertEventBelongsToSchool($school, $event);

        $event->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedEventData(Request $request, School $school, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'title' => [$required, 'string', 'max:160'],
            'eventType' => [$required, Rule::in(self::EVENT_TYPES)],
            'academicSessionId' => [
                'nullable',
                'integer',
                Rule::exists('academic_sessions', 'id')->where('school_id', $school->id),
            ],
            'academicTermId' => [
                'nullable',
                'integer',
                Rule::exists('academic_terms', 'id')->where('school_id', $school->id),
            ],
            'startAt' => [$required, 'date'],
            'endAt' => ['nullable', 'date'],
            'allDay' => ['nullable', 'boolean'],
            'location' => ['nullable', 'string', 'max:160'],
            'audience' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:4000'],
        ]);

        $attributes = [];

        foreach ([
            'title' => 'title',
            'eventType' => 'event_type',
            'academicSessionId' => 'academic_session_id',
            'academicTermId' => 'academic_term_id',
            'startAt' => 'start_at',
            'endAt' => 'end_at',
            'allDay' => 'all_day',
            'location' => 'location',
            'audience' => 'audience',
            'description' => 'description',
        ] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        return $attributes;
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

    private function assertEventBelongsToSchool(School $school, SchoolEvent $event): void
    {
        abort_unless((int) $event->school_id === (int) $school->id, 404);
    }
}
