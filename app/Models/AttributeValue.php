<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttributeValue extends Model
{
    protected $fillable = ['attribute_master_id', 'value', 'sort_order'];
    
    public function attributeMaster()
    {
        return $this->belongsTo(AttributeMaster::class);
    }
}