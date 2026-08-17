<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayoutEntry extends Model
{
    protected $fillable = [
        'payout_run_id', 'distributor_id', 'gross_commission', 
        'tds', 'net_payable', 'status', 'held_reason'
    ];

    public function payoutRun()
    {
        return $this->belongsTo(PayoutRun::class);
    }

    public function distributor()
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }
}