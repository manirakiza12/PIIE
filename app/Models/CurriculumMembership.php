<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurriculumMembership extends Model
{
    public const CLASSIFICATIONS = ['compulsory', 'elective'];
    public const PERIOD_TYPES = ['semester', 'term'];

    protected $fillable = ['period_type', 'period_sequence', 'classification', 'credits', 'sequence'];

    protected $casts = [
        'period_sequence' => 'integer',
        'credits' => 'decimal:2',
        'sequence' => 'integer',
    ];

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class, 'curriculum_id');
    }

    public function stage()
    {
        return $this->belongsTo(CurriculumStage::class, 'curriculum_stage_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function courseOfferingApplicabilities()
    {
        return $this->hasMany(CourseOfferingCurriculumMembership::class, 'curriculum_membership_id')
            ->where('school_id', $this->school_id)
            ->where('curriculum_id', $this->curriculum_id);
    }

    public function prerequisites()
    {
        return $this->belongsToMany(
            self::class,
            'curriculum_prerequisites',
            'membership_id',
            'prerequisite_membership_id'
        )->withPivot(['school_id', 'curriculum_id']);
    }

    public function requiredBy()
    {
        return $this->belongsToMany(
            self::class,
            'curriculum_prerequisites',
            'prerequisite_membership_id',
            'membership_id'
        )->withPivot(['school_id', 'curriculum_id']);
    }
}
