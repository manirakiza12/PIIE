<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicUnit extends Model
{
    public const TYPE_SCHOOL = 'school';
    public const TYPE_FACULTY = 'faculty';
    public const TYPES = [self::TYPE_SCHOOL, self::TYPE_FACULTY];

    protected $fillable = ['school_id', 'type', 'name', 'code', 'head_title', 'head_name', 'description', 'status', 'parent_id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'academic_unit_id');
    }

    public function getHierarchyLabelAttribute(): string
    {
        $labels = [];
        $cursor = $this;
        $seen = [];
        while ($cursor && ! in_array((int) $cursor->id, $seen, true)) {
            $seen[] = (int) $cursor->id;
            $labels[] = $cursor->name.' - '.ucfirst($cursor->type);
            $cursor = $cursor->parent;
        }
        return implode(' / ', array_reverse($labels));
    }
}
