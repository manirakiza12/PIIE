<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TuitionPaymentTombstone extends Model
{
    protected static function booted(): void
    {
        // Tombstones are immutable: ordinary application actions cannot alter or delete them.
        static::updating(function () { throw new \RuntimeException('Tombstone records are immutable and cannot be updated.'); });
        static::deleting(function () { throw new \RuntimeException('Tombstone records cannot be deleted by application actions.'); });
    }

    protected $table = 'tuition_payment_tombstones';
    protected $guarded = ['id'];
    protected $casts = [
        'payment_snapshot' => 'array',
        'amount' => 'decimal:2',
        'deleted_at' => 'datetime',
    ];

    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}

