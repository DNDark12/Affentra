<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffiliateBilling extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_connection_id',
        'user_id',
        'platform',
        'billing_id',
        'period_start',
        'period_end',
        'total_commission',
        'service_fee',
        'net_amount',
        'status',
        'raw_json'
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'total_commission' => 'decimal:2',
        'service_fee' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'raw_json' => 'array'
    ];
}
