<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    use HasFactory;
    protected $table = 'payroll';
    protected $fillable = [
        'school_id', 'staff_id', 'pay_period', 'basic_salary', 'allowances',
        'other_earnings', 'deductions', 'tax', 'nssf', 'employer_nssf', 'net_pay',
        'payment_method', 'bank_account', 'currency', 'paid_amount',
        'payment_reference', 'status', 'paid_at', 'paid_by', 'approved_by',
        'approved_at', 'rejected_at', 'rejection_reason', 'created_by',
    ];

    protected $casts = [
        'pay_period' => 'date',
        'paid_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'basic_salary' => 'float',
        'allowances' => 'float',
        'other_earnings' => 'float',
        'deductions' => 'float',
        'tax' => 'float',
        'nssf' => 'float',
        'employer_nssf' => 'float',
        'net_pay' => 'float',
        'paid_amount' => 'float',
    ];

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments()
    {
        return $this->hasMany(PayrollPayment::class, 'payroll_id');
    }

    /** Employee-side statutory deductions (PAYE + NSSF employee). */
    public function totalEmployeeDeductions(): float
    {
        return round((float) $this->tax + (float) $this->nssf + (float) $this->deductions, 2);
    }

    public function grossPay(): float
    {
        return round((float) $this->basic_salary + (float) $this->allowances + (float) $this->other_earnings, 2);
    }

    /** 0..1 — how much of the net salary has actually been paid out. */
    public function paidRatio(): float
    {
        $net = (float) $this->net_pay;

        return $net > 0 ? min(1.0, round((float) $this->paid_amount / $net, 4)) : 0.0;
    }

    public function isRejected(): bool
    {
        return $this->status === 'draft' && $this->rejected_at !== null;
    }

    public function isFullyPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isPartiallyPaid(): bool
    {
        return $this->status === 'approved' && (float) $this->paid_amount > 0;
    }

    /**
     * The active salary structure that produced (or will produce) this payslip.
     * Compatibility helper — the module previously used a query builder here;
     * it now delegates to the shared scoping used by the service.
     */
    public function salaryStructure()
    {
        return SalaryStructure::where('user_id', $this->staff_id)
            ->where('school_id', $this->school_id)
            ->orderByDesc('effective_from')
            ->first();
    }
}
