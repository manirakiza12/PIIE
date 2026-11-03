<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentCreditTransfer extends Model
{
    use SoftDeletes;

    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $table = 'student_credit_transfers';

    protected $fillable = [
        'school_id', 'student_id', 'programme_id', 'curriculum_id', 'source_institution',
        'source_qualification', 'credits', 'reason', 'evidence', 'status', 'requested_by',
        'decided_by', 'decided_at', 'decision_notes',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'student_id' => 'integer',
        'programme_id' => 'integer',
        'curriculum_id' => 'integer',
        'credits' => 'decimal:2',
        'requested_by' => 'integer',
        'decided_by' => 'integer',
        'decided_at' => 'datetime',
        'evidence' => 'array',
    ];

    private bool $domainWriteAllowed = false;

    protected static function booted(): void
    {
        static::creating(function (self $ct): void {
            if (! $ct->domainWriteAllowed) {
                throw new DomainException('Student Credit Transfers may only be created through CreditTransferService.');
            }
        });
        static::updating(function (self $ct): void {
            if (! $ct->domainWriteAllowed) {
                throw new DomainException('Student Credit Transfer changes must go through CreditTransferService.');
            }
        });
        static::deleting(function (): never {
            throw new DomainException('Student Credit Transfers cannot be deleted; reject instead.');
        });
    }

    public static function createThroughDomainService(array $attributes): self
    {
        $ct = new self();
        $ct->domainWriteAllowed = true;
        try {
            $ct->forceFill($attributes)->save();
        } finally {
            $ct->domainWriteAllowed = false;
        }
        return $ct;
    }

    public function updateThroughDomainService(array $attributes): void
    {
        $this->domainWriteAllowed = true;
        try {
            $this->forceFill($attributes)->save();
        } finally {
            $this->domainWriteAllowed = false;
        }
    }

    public function school()
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id')->where('school_id', $this->school_id);
    }

    public function programme()
    {
        return $this->belongsTo(Programme::class, 'programme_id')->where('school_id', $this->school_id);
    }

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class, 'curriculum_id')->where('school_id', $this->school_id);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
