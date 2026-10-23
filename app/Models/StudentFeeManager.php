<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentFeeManager extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::created(fn (self $invoice) => \App\Support\Billing\InvoiceEmail::created($invoice));
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'title', 'total_amount', 'class_id', 'parent_id','student_id', 'fee_structure_id', 'payment_method', 'paid_amount', 'status', 'school_id', 'session_id', 'timestamp', 'discounted_price', 'amount',
        'programme_id', 'gateway_reference', 'gateway_payload',
    ];

    protected $casts = [
        'gateway_payload' => 'array',
        'opening_paid_amount' => 'decimal:2',
    ];

    public function openingPayments() { return $this->hasMany(StudentOpeningPayment::class, 'fee_id')->where('school_id', $this->school_id); }

    public function hasOpeningPaymentHistory(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('student_opening_payments') && $this->openingPayments()->exists();
    }

    public function getCollectedAmountAttribute(): float
    {
        return max(0, (float) $this->paid_amount - (float) ($this->opening_paid_amount ?? 0));
    }

    /** Excludes imported money from new-collection reports; compatible with legacy test fixtures. */
    public static function collectionAmountSql(): string
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('student_fee_managers', 'opening_paid_amount')
            ? '(paid_amount - opening_paid_amount)' : 'paid_amount';
    }

    // fee_id is the invoice primary key and is globally unique. Avoid capturing
    // this model's school_id in the relation: eager loads have no single parent
    // school value, so that constraint incorrectly becomes school_id IS NULL.
    public function awards() { return $this->hasMany(StudentFeeAward::class, 'fee_id'); }

    public function hasAwardHistory(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('student_fee_awards') && $this->awards()->exists();
    }

    public function student() { return $this->belongsTo(User::class, 'student_id'); }
}
