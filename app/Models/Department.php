<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'code', 'hod_name', 'school_id', 'academic_unit_id'
    ];

    public function academicUnit(): BelongsTo
    {
        return $this->belongsTo(AcademicUnit::class, 'academic_unit_id');
    }

    public function programmes(): HasMany
    {
        return $this->hasMany(Programme::class, 'department_id');
    }
}
