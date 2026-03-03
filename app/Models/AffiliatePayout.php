<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliatePayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_connection_id',
        'payout_batch_id',
        'user_id',
        'platform',
        'payout_id',
        'payout_at',
        'amount',
        'currency',
        'bank_name',
        'account_number_masked',
        'status',
        'raw_json'
    ];

    protected $casts = [
        'payout_at' => 'datetime',
        'amount' => 'decimal:2',
        'raw_json' => 'array'
    ];

    public function payoutBatch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
