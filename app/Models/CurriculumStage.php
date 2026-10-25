<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurriculumStage extends Model
{
    protected $fillable = ['label', 'sequence'];

    protected $casts = ['sequence' => 'integer', 'archived_at' => 'datetime'];

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class, 'curriculum_id');
    }

    public function memberships()
    {
        return $this->hasMany(CurriculumMembership::class, 'curriculum_stage_id');
    }
}
