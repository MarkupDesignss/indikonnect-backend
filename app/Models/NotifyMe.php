<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotifyMe extends Model
{
    protected $fillable = ['user_id', 'product_id', 'variant_id', 'notified'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
