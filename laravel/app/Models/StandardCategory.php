<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StandardCategory extends Model
{
    use HasFactory;

    protected $table = 'standard_categories';

    protected $primaryKey = 'standard_category_id';

    protected $fillable = ['sc_name', 'Standardtable'];

    public function courses()
    {
        return $this->hasMany(Course::class, 'standard_category_id', 'standard_category_id');
    }

    public function standards()
    {
        return $this->hasMany(Standard::class, 'standard_category_id', 'standard_category_id');
    }

}
