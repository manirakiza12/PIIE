<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class LibraryReservation extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['expires_on' => 'date'];
    public function book() { return $this->belongsTo(Book::class); }
    public function borrower() { return $this->belongsTo(User::class, 'user_id'); }
}
