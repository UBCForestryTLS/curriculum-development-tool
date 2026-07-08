<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StandardsScaleCategory extends Model
{
    use HasFactory;

    protected $table = 'standards_scale_categories';

    protected $primaryKey = 'scale_category_id';

    protected $fillable = ['name', 'description', 'Scaletable'];

    public function courses()
    {
        return $this->hasMany(Course::class, 'scale_category_id', 'scale_category_id');
    }

    public function standardScales()
    {
        return $this->hasMany(StandardScale::class, 'scale_category_id', 'scale_category_id');
    }

}
