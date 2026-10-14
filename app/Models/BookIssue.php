<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookIssue extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'book_id', 'class_id', 'student_id', 'issue_date', 'status', 'school_id', 'session_id', 'timestamp',
        'copy_id', 'due_date', 'returned_at', 'renewal_count', 'issued_by', 'returned_by', 'return_condition', 'notes'
    ];

    protected $casts = ['due_date' => 'date', 'returned_at' => 'datetime'];
    public function book() { return $this->belongsTo(Book::class); }
    public function copy() { return $this->belongsTo(LibraryCopy::class, 'copy_id'); }
    public function borrower() { return $this->belongsTo(User::class, 'student_id'); }
}
