<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Curriculum extends Model
{
    public const STATUSES = ['draft', 'approved', 'retired'];

    protected $fillable = ['version'];

    protected $casts = [
        'effective_academic_year_id' => 'integer',
    ];

    public function school()
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function programme()
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    public function effectiveAcademicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'effective_academic_year_id');
    }

    public function stages()
    {
        return $this->hasMany(CurriculumStage::class)->orderBy('sequence');
    }

    public function memberships()
    {
        return $this->hasMany(CurriculumMembership::class)->orderBy('sequence');
    }

    public function courseOfferingApplicabilities()
    {
        return $this->hasMany(CourseOfferingCurriculumMembership::class, 'curriculum_id')->where('school_id', $this->school_id);
    }

    public function studentAssignments()
    {
        return $this->hasMany(StudentCurriculumAssignment::class, 'curriculum_id')->where('school_id', $this->school_id);
    }
}
