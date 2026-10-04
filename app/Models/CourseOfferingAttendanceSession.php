<?php

namespace App\Models;

use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceSessionStatus;
use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceSessionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One teaching occurrence of a Course Offering - "Lecture on 5 October 2026,
 * 09:00-11:00". A Course Offering meets many times, so attendance is recorded
 * against occurrences rather than against the Course Offering directly.
 *
 * Governed through CourseOfferingSupport\CourseOfferingAttendanceService, which
 * refuses direct writes exactly as CourseOffering and
 * CourseOfferingLecturerAllocation do.
 */
class CourseOfferingAttendanceSession extends Model
{
    protected $table = 'course_offering_attendance_sessions';

    protected $guarded = ['*'];

    protected $casts = [
        'school_id' => 'integer',
        'course_offering_id' => 'integer',
        'live_class_id' => 'integer',
        'recorded_by_user_id' => 'integer',
        'session_date' => 'date:Y-m-d',
    ];

    protected static function booted(): void
    {
        static::creating(function (): void {
            throw new \DomainException('Attendance Sessions may only be created through CourseOfferingAttendanceService.');
        });

        static::updating(function (): void {
            throw new \DomainException('Attendance Sessions may only be changed through CourseOfferingAttendanceService.');
        });

        static::deleting(function (): void {
            throw new \DomainException('Attendance Sessions are academic records and cannot be deleted.');
        });
    }

    public function offering()
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id')
            ->where('school_id', $this->school_id);
    }

    public function liveClass()
    {
        return $this->belongsTo(LiveClass::class, 'live_class_id')
            ->where('school_id', $this->school_id);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function records()
    {
        return $this->hasMany(CourseOfferingAttendanceRecord::class, 'attendance_session_id')
            ->where('school_id', $this->school_id);
    }

    public function scopeForSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    public function statusLabel(): string
    {
        return CourseOfferingAttendanceSessionStatus::label($this->status);
    }

    public function typeLabel(): string
    {
        return CourseOfferingAttendanceSessionType::label($this->type);
    }

    public function acceptsMarking(): bool
    {
        return CourseOfferingAttendanceSessionStatus::acceptsMarking($this->status);
    }

    /** Human time range, or null when the occurrence is recorded without times. */
    public function timeRange(): ?string
    {
        if (! $this->starts_at) {
            return null;
        }

        return $this->starts_at.' – '.($this->ends_at ?: 'end of session');
    }
}
