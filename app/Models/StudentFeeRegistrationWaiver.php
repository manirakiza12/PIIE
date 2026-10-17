<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentFeeRegistrationWaiver extends Model
{
    protected $table = 'student_fee_registration_waivers';
    protected $guarded = ['id'];

    protected $casts = [
        'school_id' => 'integer',
        'student_id' => 'integer',
        'session_id' => 'integer',
        'granted_by' => 'integer',
        'revoked_by' => 'integer',
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
