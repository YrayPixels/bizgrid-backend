<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimetablePeriod extends Model
{
    /** @var list<string> */
    public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    protected $fillable = [
        'school_id',
        'academic_session_id',
        'academic_term_id',
        'school_class_id',
        'subject_id',
        'teacher_employee_id',
        'weekday',
        'start_time',
        'end_time',
        'room',
        'notes',
        'status',
        'created_by',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'teacher_employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
