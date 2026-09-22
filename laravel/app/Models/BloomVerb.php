<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BloomVerb extends Model
{
    protected $fillable = ['level_id', 'term'];

    public function level(): BelongsTo
    {
        return $this->belongsTo(BloomLevel::class, 'level_id');
    }
}
