<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class StudentCurriculumAssignment extends Model
{
    protected $guarded = ['*'];

    private bool $domainWriteAllowed = false;

    protected $casts = [
        'school_id' => 'integer',
        'student_id' => 'integer',
        'programme_id' => 'integer',
        'curriculum_id' => 'integer',
        'entry_academic_year_id' => 'integer',
        'effective_from_academic_year_id' => 'integer',
        'assigned_by' => 'integer',
        'programme_cohort_membership_id' => 'integer',
        'entry_curriculum_stage_id' => 'integer',
        'ended_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $assignment): void {
            if (! $assignment->domainWriteAllowed) {
                throw new DomainException('Student Curriculum assignments may only be created through StudentCurriculumAssignmentService.');
            }
        });

        static::updating(function (self $assignment): void {
            $identity = [
                'school_id', 'student_id', 'programme_id', 'curriculum_id',
                'entry_academic_year_id', 'effective_from_academic_year_id', 'assigned_by',
            ];
            foreach ($identity as $field) {
                if ($assignment->isDirty($field)) {
                    throw new DomainException('Student Curriculum assignment identity is immutable.');
                }
            }

            if (! $assignment->domainWriteAllowed || $assignment->getOriginal('ended_at') !== null || $assignment->ended_at === null || $assignment->isDirty('reason')) {
                throw new DomainException('Only an active assignment may be ended; assignment history cannot be edited.');
            }
        });

        static::deleting(function (): never {
            throw new DomainException('Student Curriculum assignments cannot be deleted.');
        });
    }

    public static function createThroughDomainService(array $attributes): self
    {
        $assignment = new self();
        $assignment->domainWriteAllowed = true;
        try {
            $assignment->forceFill($attributes)->save();
        } finally {
            $assignment->domainWriteAllowed = false;
        }
        return $assignment;
    }

    public function endThroughDomainService(\DateTimeInterface $endedAt): void
    {
        $this->domainWriteAllowed = true;
        try {
            $this->ended_at = $endedAt;
            $this->save();
        } finally {
            $this->domainWriteAllowed = false;
        }
    }

    public function updateThroughDomainService(array $attributes): void
    {
        $this->domainWriteAllowed = true;
        try {
            $this->forceFill($attributes)->save();
        } finally {
            $this->domainWriteAllowed = false;
        }
    }

    public function school()
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function programme()
    {
        return $this->belongsTo(Programme::class, 'programme_id')->where('school_id', $this->school_id);
    }

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class, 'curriculum_id')->where('school_id', $this->school_id);
    }

    public function entryAcademicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'entry_academic_year_id')->where('school_id', $this->school_id);
    }

    public function effectiveFromAcademicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'effective_from_academic_year_id')->where('school_id', $this->school_id);
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function programmeCohortMembership()
    {
        return $this->belongsTo(ProgrammeCohortMembership::class, 'programme_cohort_membership_id');
    }

    public function entryCurriculumStage()
    {
        return $this->belongsTo(CurriculumStage::class, 'entry_curriculum_stage_id');
    }
}
