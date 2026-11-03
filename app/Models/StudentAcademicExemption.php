<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentAcademicExemption extends Model
{
    use SoftDeletes;

    public const TYPES = ['level', 'course_unit'];
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected $table = 'student_academic_exemptions';

    protected $fillable = [
        'school_id', 'student_id', 'programme_id', 'curriculum_id', 'exemption_type',
        'target_curriculum_stage_id', 'status', 'previous_institution', 'previous_qualification',
        'previous_results', 'reason', 'evidence', 'requested_by', 'decided_by', 'decided_at',
        'decision_notes',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'student_id' => 'integer',
        'programme_id' => 'integer',
        'curriculum_id' => 'integer',
        'target_curriculum_stage_id' => 'integer',
        'requested_by' => 'integer',
        'decided_by' => 'integer',
        'decided_at' => 'datetime',
        'evidence' => 'array',
    ];

    private bool $domainWriteAllowed = false;

    protected static function booted(): void
    {
        static::creating(function (self $exemption): void {
            if (! $exemption->domainWriteAllowed) {
                throw new DomainException('Student Academic Exemptions may only be created through ExemptionService.');
            }
        });
        static::updating(function (self $exemption): void {
            if (! $exemption->domainWriteAllowed) {
                throw new DomainException('Student Academic Exemption changes must go through ExemptionService.');
            }
        });
        static::deleting(function (): never {
            throw new DomainException('Student Academic Exemptions cannot be deleted; cancel or reject instead.');
        });
    }

    public static function createThroughDomainService(array $attributes): self
    {
        $exemption = new self();
        $exemption->domainWriteAllowed = true;
        try {
            $exemption->forceFill($attributes)->save();
        } finally {
            $exemption->domainWriteAllowed = false;
        }
        return $exemption;
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

    public function targetStage()
    {
        return $this->belongsTo(CurriculumStage::class, 'target_curriculum_stage_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function items()
    {
        return $this->hasMany(StudentAcademicExemptionItem::class, 'exemption_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
