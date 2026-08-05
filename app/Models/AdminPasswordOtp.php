<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminPasswordOtp extends Model
{
    protected $table = 'admin_password_otps';

    protected $guarded = [];

    public $timestamps = false;
}
