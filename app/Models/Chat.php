<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chat extends Model
{
    use HasFactory;
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $fillable = [
        'client_message_id', 'message_thrade', 'reciver_id', 'sender_id', 'message', 'reply_id', 'school_id', 'read_status', 'created_at', 'created_at'
    ];


}
