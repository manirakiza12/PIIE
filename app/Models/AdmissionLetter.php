<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdmissionLetter extends Model
{
    protected $fillable = ['school_id', 'admission_id', 'student_id', 'snapshot', 'issued_at'];
    protected $hidden = ['snapshot'];
    protected $casts = ['snapshot' => 'encrypted:array', 'issued_at' => 'datetime'];
}
