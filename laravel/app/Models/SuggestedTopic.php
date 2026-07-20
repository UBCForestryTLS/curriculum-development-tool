<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SuggestedTopic extends Model
{
    use HasFactory;

    protected $table = 'suggested_topics';

    protected $primaryKey = 'suggested_topic_id';

    protected $fillable = ['course_material_file_id', 'topic', 'score', 'status'];

    protected $casts = [
        'score' => 'float',
    ];

    public const STATUS_PENDING = 0;
    public const STATUS_CONFIRMED = 1;
    public const STATUS_REJECTED = 2;

    public function courseMaterialFile()
    {
        return $this->belongsTo(CourseMaterialFile::class, 'course_material_file_id', 'course_material_file_id');
    }
}
