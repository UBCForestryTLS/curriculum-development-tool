<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseMaterialChunk extends Model
{
    use HasFactory;

    protected $table = 'course_material_chunks';

    protected $fillable = [
        'course_material_file_id',
        'page_number',
        'chunk_index',
        'content',
    ];

    public function file()
    {
        return $this->belongsTo(CourseMaterialFile::class, 'course_material_file_id');
    }
}
