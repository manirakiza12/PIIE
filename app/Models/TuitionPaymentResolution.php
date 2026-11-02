<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TuitionPaymentResolution extends Model
{
    protected $table = 'tuition_payment_resolutions';
    protected $guarded = ['id'];
    protected $casts = [
        'payment_snapshot' => 'array',
        'verification_evidence' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function payment()
    {
        return $this->belongsTo(TuitionPayment::class, 'payment_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}