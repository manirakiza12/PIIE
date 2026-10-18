<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class CourseOfferingCurriculumMembership extends Model
{
    protected $table = 'course_offering_curriculum_memberships';

    protected $guarded = ['*'];

    protected $casts = [
        'school_id' => 'integer',
        'course_offering_id' => 'integer',
        'curriculum_id' => 'integer',
        'curriculum_membership_id' => 'integer',
        'subject_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (): void {
            throw new DomainException('Allocation applicability may only be attached through CourseOfferingService.');
        });

        static::updating(function (): void {
            throw new DomainException('Allocation applicability identity cannot be edited directly.');
        });

        static::deleting(function (): void {
            throw new DomainException('Allocation applicability may only be removed through CourseOfferingService.');
        });
    }

    public function offering()
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id')->where('school_id', $this->school_id);
    }

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class, 'curriculum_id')->where('school_id', $this->school_id);
    }

    public function curriculumMembership()
    {
        return $this->belongsTo(CurriculumMembership::class, 'curriculum_membership_id')->where('school_id', $this->school_id);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id')->where('school_id', $this->school_id);
    }
}
