<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollSetting extends Model
{
    protected $table = 'payroll_settings';

    protected $fillable = [
        'school_id', 'paye_bands', 'nssf_employee_rate', 'nssf_employer_rate',
        'paye_surcharge_threshold', 'paye_surcharge_rate', 'notes',
    ];

    protected $casts = [
        'paye_bands' => 'array',
        'nssf_employee_rate' => 'float',
        'nssf_employer_rate' => 'float',
        'paye_surcharge_threshold' => 'float',
        'paye_surcharge_rate' => 'float',
    ];
}
