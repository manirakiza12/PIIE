<?php

namespace App\Models;

use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One student's status in one Course Offering attendance session.
 *
 * `course_registration_id` is the authoritative academic participation anchor:
 * it is what ties the mark to a governed registration rather than to a bare
 * student id. `student_id` is a denormalised lookup field kept for cheap
 * student-scoped history; the service asserts it matches the registration.
 */
class CourseOfferingAttendanceRecord extends Model
{
    protected $table = 'course_offering_attendance_records';

    protected $guarded = ['*'];

    protected $casts = [
        'school_id' => 'integer',
        'attendance_session_id' => 'integer',
        'course_registration_id' => 'integer',
        'student_id' => 'integer',
        'marked_by_user_id' => 'integer',
        'status' => 'integer',
        'marked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (): void {
            throw new \DomainException('Attendance records may only be written through CourseOfferingAttendanceService.');
        });

        static::updating(function (): void {
            throw new \DomainException('Attendance records may only be changed through CourseOfferingAttendanceService.');
        });

        static::deleting(function (): void {
            throw new \DomainException('Attendance records are academic records and cannot be deleted.');
        });
    }

    public function session()
    {
        return $this->belongsTo(CourseOfferingAttendanceSession::class, 'attendance_session_id')
            ->where('school_id', $this->school_id);
    }

    public function registration()
    {
        return $this->belongsTo(CourseRegistration::class, 'course_registration_id')
            ->where('school_id', $this->school_id);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function marker()
    {
        return $this->belongsTo(User::class, 'marked_by_user_id');
    }

    public function scopeForSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeForSession(Builder $query, int $sessionId): Builder
    {
        return $query->where('attendance_session_id', $sessionId);
    }

    public function statusLabel(): string
    {
        return CourseOfferingAttendanceStatus::label($this->status);
    }

    /** Present and Late both count as having attended; Excused is not an absence. */
    public function countsAsAttended(): bool
    {
        return in_array($this->status, CourseOfferingAttendanceStatus::ATTENDED, true);
    }
}
