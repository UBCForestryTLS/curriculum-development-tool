<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MappingScaleProgram extends Model
{
    use HasFactory;

    protected $primaryKey = ['map_scale_id', 'program_id'];

    public $incrementing = false;

    protected $fillable = ['map_scale_id', 'program_id', 'position'];

    public static function nextPositionForProgram(int $programId): int
    {
        return ((int) static::where('program_id', $programId)->max('position')) + 1;
    }

    public function mappingScales()
    {
        return $this->belongsTo(MappingScale::class);
    }

    public function programs()
    {
        return $this->belongsTo(Program::class);
    }
}
