<?php

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Employee;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolEvent;
use App\Models\Subject;
use App\Models\TimetablePeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createTimetableFixture(): array
{
    $user = User::factory()->create();
    $school = School::create([
        'name' => 'Test School',
        'slug' => 'test-school-'.uniqid(),
        'created_by' => $user->id,
        'status' => 'active',
    ]);

    $school->users()->attach($user->id, ['role' => 'owner']);

    $session = AcademicSession::create([
        'school_id' => $school->id,
        'name' => '2026/2027',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'active',
    ]);

    $term = AcademicTerm::create([
        'school_id' => $school->id,
        'academic_session_id' => $session->id,
        'name' => 'First Term',
        'start_date' => '2026-01-01',
        'end_date' => '2026-04-30',
        'is_current' => true,
    ]);

    $class = SchoolClass::create([
        'school_id' => $school->id,
        'name' => 'JSS 1',
        'section' => 'A',
    ]);

    $subject = Subject::create([
        'school_id' => $school->id,
        'name' => 'Mathematics',
        'code' => 'MATH',
    ]);

    $teacher = Employee::create([
        'school_id' => $school->id,
        'staff_id' => 'T-001',
        'first_name' => 'Ada',
        'last_name' => 'Okafor',
        'role' => 'Teacher',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    return compact('user', 'school', 'session', 'term', 'class', 'subject', 'teacher');
}

it('lists timetable periods and calendar events for a session and term', function () {
    $fixture = createTimetableFixture();
    extract($fixture);

    TimetablePeriod::create([
        'school_id' => $school->id,
        'academic_session_id' => $session->id,
        'academic_term_id' => $term->id,
        'school_class_id' => $class->id,
        'subject_id' => $subject->id,
        'teacher_employee_id' => $teacher->id,
        'weekday' => 'monday',
        'start_time' => '09:00',
        'end_time' => '09:45',
        'status' => 'active',
    ]);

    SchoolEvent::create([
        'school_id' => $school->id,
        'academic_session_id' => $session->id,
        'academic_term_id' => $term->id,
        'title' => 'PTA Meeting',
        'event_type' => 'meeting',
        'start_at' => '2026-02-12 10:00:00',
        'end_at' => '2026-02-12 11:30:00',
        'all_day' => false,
        'location' => 'School hall',
        'created_by' => $user->id,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/schools/{$school->id}/timetable-periods?".http_build_query([
        'academicSessionId' => $session->id,
        'academicTermId' => $term->id,
        'from' => '2026-02-01',
        'to' => '2026-02-28',
    ]));

    $response->assertOk()
        ->assertJsonCount(1, 'periods')
        ->assertJsonPath('periods.0.weekday', 'monday')
        ->assertJsonPath('periods.0.subject.name', 'Mathematics')
        ->assertJsonCount(1, 'events')
        ->assertJsonPath('events.0.title', 'PTA Meeting')
        ->assertJsonPath('events.0.event_type', 'meeting');
});

it('creates a timetable period', function () {
    $fixture = createTimetableFixture();
    extract($fixture);

    Sanctum::actingAs($user);

    $response = $this->postJson("/api/schools/{$school->id}/timetable-periods", [
        'academicSessionId' => $session->id,
        'academicTermId' => $term->id,
        'schoolClassId' => $class->id,
        'subjectId' => $subject->id,
        'teacherEmployeeId' => $teacher->id,
        'weekday' => 'tuesday',
        'startTime' => '10:00',
        'endTime' => '10:45',
        'room' => 'Lab 2',
    ]);

    $response->assertCreated()
        ->assertJsonPath('period.weekday', 'tuesday')
        ->assertJsonPath('period.room', 'Lab 2');

    $this->assertDatabaseHas('timetable_periods', [
        'school_id' => $school->id,
        'weekday' => 'tuesday',
        'room' => 'Lab 2',
    ]);
});

it('rejects overlapping teacher periods', function () {
    $fixture = createTimetableFixture();
    extract($fixture);

    TimetablePeriod::create([
        'school_id' => $school->id,
        'academic_session_id' => $session->id,
        'academic_term_id' => $term->id,
        'school_class_id' => $class->id,
        'subject_id' => $subject->id,
        'teacher_employee_id' => $teacher->id,
        'weekday' => 'wednesday',
        'start_time' => '11:00',
        'end_time' => '11:45',
        'status' => 'active',
    ]);

    Sanctum::actingAs($user);

    $response = $this->postJson("/api/schools/{$school->id}/timetable-periods", [
        'academicSessionId' => $session->id,
        'academicTermId' => $term->id,
        'schoolClassId' => $class->id,
        'subjectId' => $subject->id,
        'teacherEmployeeId' => $teacher->id,
        'weekday' => 'wednesday',
        'startTime' => '11:30',
        'endTime' => '12:15',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['startTime']);
});

it('deletes a timetable period', function () {
    $fixture = createTimetableFixture();
    extract($fixture);

    $period = TimetablePeriod::create([
        'school_id' => $school->id,
        'academic_session_id' => $session->id,
        'academic_term_id' => $term->id,
        'school_class_id' => $class->id,
        'subject_id' => $subject->id,
        'teacher_employee_id' => $teacher->id,
        'weekday' => 'friday',
        'start_time' => '14:00',
        'end_time' => '14:45',
        'status' => 'active',
    ]);

    Sanctum::actingAs($user);

    $response = $this->deleteJson("/api/schools/{$school->id}/timetable-periods/{$period->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('timetable_periods', ['id' => $period->id]);
});
