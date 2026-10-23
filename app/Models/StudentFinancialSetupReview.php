<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentFinancialSetupReview extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'prior_payment' => 'array',
        'award' => 'array',
        'study_waiver' => 'array',
        'prior_payment_confirmed_at' => 'datetime',
        'award_confirmed_at' => 'datetime',
        'study_waiver_confirmed_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(StudentFeeManager::class, 'fee_id');
    }
}
