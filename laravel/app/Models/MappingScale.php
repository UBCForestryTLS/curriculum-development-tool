<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MappingScale extends Model
{
    use HasFactory;

    protected $table = 'mapping_scales';

    protected $primaryKey = 'map_scale_id';

    protected $fillable = ['map_scale_id', 'title', 'abbreviation', 'description', 'colour', 'mapping_scale_categories_id'];

    public function programs()
    {
        return $this->belongsToMany(Program::class, 'mapping_scale_programs', 'map_scale_id', 'program_id')->withTimestamps();
    }

    public function mappingScalePrograms()
    {
        return $this->hasMany(MappingScaleProgram::class);
    }
}
