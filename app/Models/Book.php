<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'author', 'copies', 'school_id', 'session_id', 'timestamp',
        'isbn', 'category', 'publisher', 'edition', 'publication_year', 'language',
        'shelf_location', 'description', 'resource_url', 'is_active', 'resource_type', 'digital_path', 'digital_name',
        'digital_mime', 'download_audience', 'replacement_cost'
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function holdings() { return $this->hasMany(LibraryCopy::class); }
    public function loans() { return $this->hasMany(BookIssue::class); }
}
