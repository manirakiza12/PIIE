<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalaryStructure extends Model
{
    use HasFactory;
    protected $table = 'salary_structures';
    protected $fillable = ['school_id', 'user_id', 'basic', 'housing', 'transport', 'medical', 'other_earnings', 'payment_method', 'bank_account', 'effective_from'];

    protected $casts = ['effective_from' => 'date'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
