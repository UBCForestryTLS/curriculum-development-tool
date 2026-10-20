<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BloomLevel extends Model
{
    protected $fillable = ['domain_id', 'position', 'name'];

    protected $casts = ['position' => 'integer'];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(BloomDomain::class, 'domain_id');
    }

    public function verbs(): HasMany
    {
        return $this->hasMany(BloomVerb::class, 'level_id');
    }
}
