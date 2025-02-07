<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'account_id',
        'user_id',
        'account_owner',
        'amount',
        'authorized_date',
        'authorized_datetime',
        'category',
        'category_id',
        'check_number',
        'date',
        'datetime',
        'iso_currency_code',
        'location',
        'merchant_name',
        'name',
        'payment_channel',
        'payment_meta',
        'pending',
        'pending_transaction_id',
        'personal_finance_category',
        'transaction_code',
        'transaction_id',
        'transaction_type',
        'unofficial_currency_code',
        'receipt',
        'json_response',
        'entry_type',
    ];
    protected $hidden = [
        'deleted_at',
    ];
    public function getAmountAttribute($value)
    {
        return abs($value);
    }
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
