<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserCategory extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'category_name',
        'limitation',
        'max_limit',
        'spending',
        'manual_spending',
        'fixed',
        'month',
    ];
    public function userCategoryPivots()
    {
        return $this->hasMany(UserCategoryPivot::class, 'user_category_id');
    }
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'user_category_pivot', 'user_category_id', 'category_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
