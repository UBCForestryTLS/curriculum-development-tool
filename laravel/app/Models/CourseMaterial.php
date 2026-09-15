<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseMaterial extends Model
{
    use HasFactory;

    protected $table = 'course_materials';

    protected $primaryKey = 'course_material_id';

    protected $fillable = [
        'course_id',
        'name',
        'type',
        'description',
        'is_required',
        'url',
        'position',
    ];

    protected $casts = ['is_required' => 'boolean'];

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id', 'course_id');
    }

    public function files()
    {
        return $this->hasMany(CourseMaterialFile::class, 'course_material_id', 'course_material_id');
    }
}
