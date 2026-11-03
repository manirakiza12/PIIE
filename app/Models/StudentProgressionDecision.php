<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class StudentProgressionDecision extends Model
{
    public const DECISIONS = ['promote', 'retain', 'conditional', 'exceptional'];

    protected $table = 'student_progression_decisions';

    protected $fillable = [
        'school_id', 'student_id', 'programme_id', 'curriculum_id', 'programme_cohort_membership_id',
        'from_level', 'to_level', 'decision', 'basis', 'notes', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'student_id' => 'integer',
        'programme_id' => 'integer',
        'curriculum_id' => 'integer',
        'programme_cohort_membership_id' => 'integer',
        'decided_by' => 'integer',
        'decided_at' => 'datetime',
        'basis' => 'array',
    ];

    private bool $domainWriteAllowed = false;

    protected static function booted(): void
    {
        static::creating(function (self $decision): void {
            if (! $decision->domainWriteAllowed) {
                throw new DomainException('Student Progression Decisions may only be created through ProgressionService.');
            }
        });
        static::updating(function (): never {
            throw new DomainException('Student Progression Decisions are immutable.');
        });
        static::deleting(function (): never {
            throw new DomainException('Student Progression Decisions cannot be deleted.');
        });
    }

    public static function createThroughDomainService(array $attributes): self
    {
        $decision = new self();
        $decision->domainWriteAllowed = true;
        try {
            $decision->forceFill($attributes)->save();
        } finally {
            $decision->domainWriteAllowed = false;
        }
        return $decision;
    }

    public function school()
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id')->where('school_id', $this->school_id);
    }

    public function programme()
    {
        return $this->belongsTo(Programme::class, 'programme_id')->where('school_id', $this->school_id);
    }

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class, 'curriculum_id')->where('school_id', $this->school_id);
    }

    public function cohortMembership()
    {
        return $this->belongsTo(ProgrammeCohortMembership::class, 'programme_cohort_membership_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
