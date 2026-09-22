<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BloomDomain extends Model
{
    protected $fillable = ['name'];

    public function levels(): HasMany
    {
        return $this->hasMany(BloomLevel::class, 'domain_id')->orderBy('position');
    }
}
