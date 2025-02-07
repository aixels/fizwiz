<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserCategoryPivot extends Model
{
    use HasFactory;

    protected $table = 'user_category_pivot';

    protected $fillable = [
        'user_category_id',
        'category_id',
    ];
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
