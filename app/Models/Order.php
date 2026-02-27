<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\Platform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'campaign_id',
        'tracking_link_id',
        'connection_id',
        'platform',
        'order_code',
        'external_order_id',
        'sub_id',
        'shop_id',
        'shop_name',
        'product_id',
        'product_model_id',
        'product_name',
        'product_link',
        'product_quantity',
        'status',
        'payout_status',
        'order_amount',
        'listed_amount',
        'commission',
        'commission_platform',
        'commission_brand',
        'commission_other',
        'ordered_at',
        'click_at',
        'approved_at',
        'completed_at',
        'paid_at',
        'payout_batch_id',
        'source',
        'source_updated_at',
        'synced_at',
        'raw_payload_hash',
        'missing_sub_id',
        'source_meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status'             => OrderStatus::class,
            'platform'           => Platform::class,
            'order_amount'       => 'decimal:2',
            'listed_amount'      => 'decimal:2',
            'commission'         => 'decimal:2',
            'commission_platform'=> 'decimal:2',
            'commission_brand'   => 'decimal:2',
            'commission_other'   => 'decimal:2',
            'ordered_at'         => 'datetime',
            'click_at'           => 'datetime',
            'approved_at'        => 'datetime',
            'completed_at'       => 'datetime',
            'paid_at'            => 'datetime',
            'source_updated_at'  => 'datetime',
            'synced_at'          => 'datetime',
            'missing_sub_id'     => 'boolean',
            'source_meta'        => 'array',
        ];
    }

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function trackingLink(): BelongsTo
    {
        return $this->belongsTo(TrackingLink::class);
    }

    public function platformConnection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class, 'connection_id');
    }

    // ─── Scopes ────────────────────────────────────────────────────────────────

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeApproved($query)
    {
        return $query->where('status', OrderStatus::Approved);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->status === OrderStatus::Approved;
    }
}
