<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class LibraryCopy extends Model
{
    protected $table = 'library_copies';
    protected $guarded = ['id'];
    public function book() { return $this->belongsTo(Book::class); }
    public function loans() { return $this->hasMany(BookIssue::class, 'copy_id'); }
}
