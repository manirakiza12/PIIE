<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class CurriculumStage extends Model
{
    protected $fillable = ['label', 'sequence'];

    protected $casts = ['sequence' => 'integer'];

    protected static function booted(): void
    {
        static::saving(fn (self $stage) => self::assertDraftOwner($stage));
        static::deleting(fn (self $stage) => self::assertDraftOwner($stage));
    }

    private static function assertDraftOwner(self $stage): void
    {
        if ($stage->curriculum_id && Curriculum::where('school_id', $stage->school_id)->whereKey($stage->curriculum_id)->value('status') !== 'draft') {
            throw new DomainException('Programme Years may only be changed while their Curriculum is draft.');
        }
    }

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class, 'curriculum_id');
    }

    public function memberships()
    {
        return $this->hasMany(CurriculumMembership::class, 'curriculum_stage_id');
    }
}
