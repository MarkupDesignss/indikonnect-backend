<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessProfile extends Model
{
    protected $table = 'business_profiles';
    protected $fillable = [
        'user_id',
        'company_name',
        'gst_number',
        'billing_address',
        'city',
        'state',
        'pin_code',
        'country',
        'document_path',
        'discount_percentage'
    ];
}
