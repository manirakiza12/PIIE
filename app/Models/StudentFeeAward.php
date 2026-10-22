<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentFeeAward extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'decimal:2', 'revoked_at' => 'datetime'];
}
