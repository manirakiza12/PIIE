<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TuitionPayment extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'decimal:2', 'gateway_payload' => 'array', 'paid_at' => 'datetime', 'resolved_at' => 'datetime'];
    public function fee() { return $this->belongsTo(StudentFeeManager::class, 'fee_id'); }
    public function resolution() { return $this->belongsTo(TuitionPaymentResolution::class, 'resolution_id'); }
    public function isResolved(): bool { return $this->resolved_at !== null; }
}
