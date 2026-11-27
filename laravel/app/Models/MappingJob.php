<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MappingJob extends Model
{
    // A job stays active while Laravel is still saving its results, even if AWS has finished
    public const ACTIVE_STATUSES = ['pending', 'in_progress', 'importing'];

    protected $primaryKey = 'job_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'job_id', 'course_id', 'program_id', 'requested_by', 'status', 'input_snapshot',
        'total_clos', 'total_plos', 'total_pairs', 'processed_pairs', 'mapped_pairs', 'errored_pairs',
    ];

    protected $casts = [
        'input_snapshot' => 'array',
        'total_clos' => 'integer',
        'total_plos' => 'integer',
        'total_pairs' => 'integer',
        'processed_pairs' => 'integer',
        'mapped_pairs' => 'integer',
        'errored_pairs' => 'integer',
    ];

    // Keep the saved request server-side when returning job progress.
    protected $hidden = ['input_snapshot'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id', 'course_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id', 'program_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
