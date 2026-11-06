<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One immutable row per recorded salary payment. Payments are never edited
 * or deleted: corrections are new reversing records (Phase 8 financial
 * control — posted figures cannot silently change).
 */
class PayrollPayment extends Model
{
    protected $table = 'payroll_payments';

    protected $fillable = [
        'school_id', 'payroll_id', 'amount', 'payment_method',
        'payment_reference', 'paid_on', 'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'paid_on' => 'date',
    ];

    public function payroll()
    {
        return $this->belongsTo(Payroll::class, 'payroll_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
