<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentOpeningPayment extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date', 'reversed_at' => 'datetime'];
}
