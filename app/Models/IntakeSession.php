<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IntakeSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'name', 'open_date', 'close_date', 'application_fee', 'is_open',
        'start_date', 'orientation_start_date', 'orientation_end_date', 'registration_deadline',
    ];

    public function admissions()
    {
        return $this->hasMany(Admission::class, 'intake_session_id');
    }

    public function programmeCohorts()
    {
        return $this->hasMany(ProgrammeCohort::class, 'intake_session_id');
    }
}
