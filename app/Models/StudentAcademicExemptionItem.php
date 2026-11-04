<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentAcademicExemptionItem extends Model
{
    protected $table = 'student_academic_exemption_items';

    protected $fillable = [
        'school_id', 'exemption_id', 'curriculum_id', 'curriculum_membership_id', 'subject_id', 'status', 'notes',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'exemption_id' => 'integer',
        'curriculum_id' => 'integer',
        'curriculum_membership_id' => 'integer',
        'subject_id' => 'integer',
    ];

    public function school()
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function exemption()
    {
        return $this->belongsTo(StudentAcademicExemption::class, 'exemption_id');
    }

    public function curriculumMembership()
    {
        return $this->belongsTo(CurriculumMembership::class, 'curriculum_membership_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }
}
