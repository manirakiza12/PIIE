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
    ];

    public function student() { return $this->belongsTo(User::class, 'student_id'); }
}
