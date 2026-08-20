<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseMaterialFile extends Model
{
    use HasFactory;

    protected $table = 'course_material_files';

    protected $primaryKey = 'course_material_file_id';

    protected $fillable = [
        'course_material_id',
        'uploaded_by',
        'file_name',
        'file_path',
        'file_size',
        'status',
        'error_message',
        'page_count',
        'ocr_enabled',
        'ocr_threshold',
        'extraction_engine',
        'processing_time_seconds',
    ];

    protected $casts = [
        'ocr_enabled' => 'boolean',
        'ocr_threshold' => 'integer',
    ];

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_INDEXING = 'INDEXING';
    public const STATUS_INDEXED = 'INDEXED';
    public const STATUS_FAILED = 'FAILED';

    public function courseMaterial()
    {
        return $this->belongsTo(CourseMaterial::class, 'course_material_id', 'course_material_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function chunks()
    {
        return $this->hasMany(CourseMaterialChunk::class, 'course_material_file_id');
    }

    public function topics()
    {
        return $this->belongsToMany(
            CourseTopic::class,
            'course_material_file_topic',
            'course_material_file_id',
            'course_topic_id'
        );
    }

    public function suggestedTopics()
    {
        return $this->hasMany(SuggestedTopic::class, 'course_material_file_id');
    }
}
