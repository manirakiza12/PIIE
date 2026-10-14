<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleMeetSetting extends Model
{
    protected $table = 'google_meet_settings';
    public $incrementing = false;
    protected $guarded = [];
    protected $hidden = ['client_secret'];
    protected $casts = ['client_secret' => 'encrypted', 'enabled' => 'boolean', 'verified_at' => 'datetime'];
}
