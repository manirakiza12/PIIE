<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationPayment extends Model
{
    use HasFactory;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_PAID     = 'paid';
    public const STATUS_FAILED   = 'failed';
    public const STATUS_WAIVED   = 'waived';
    public const STATUS_REJECTED = 'rejected';

    /** Historical public location, retained only for authenticated compatibility. */
    public const PROOF_DIR = 'assets/uploads/application_payments';

    protected $fillable = [
        'school_id', 'admission_id', 'applicant_id', 'amount', 'currency',
        'method', 'status', 'reference', 'gateway_txn_id', 'gateway_payload',
        'proof_file', 'note', 'confirmed_by', 'paid_at',
    ];

    protected $casts = [
        'gateway_payload' => 'array',
        'paid_at'         => 'datetime',
        'amount'          => 'decimal:2',
    ];

    public function admission()
    {
        return $this->belongsTo(Admission::class, 'admission_id');
    }

    public function applicant()
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }

    public function getProofUrlAttribute(): ?string
    {
        return $this->proof_file ? route('admin.hei_admissions.payment.proof', $this->id) : null;
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_WAIVED], true);
    }
}
