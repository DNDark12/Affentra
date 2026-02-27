<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\Platform;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'external_id',
        'name',
        'platform',
        'source',
        'status',
        'external_status',
        'date_start',
        'date_end',
        'commission_rate',
        'goal_amount',
        'description',
        'campaign_url',
        'impressions',
        'clicks',
        'banner_image_id',
        'synced_at',
        'source_meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_start'      => 'date',
            'date_end'        => 'date',
            'commission_rate' => 'decimal:2',
            'goal_amount'     => 'decimal:2',
            'impressions'     => 'integer',
            'clicks'          => 'integer',
            'synced_at'       => 'datetime',
            'source_meta'     => 'array',
            'status'          => CampaignStatus::class,
            'platform'        => Platform::class,
        ];
    }

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trackingLinks(): HasMany
    {
        return $this->hasMany(TrackingLink::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeActive($query)
    {
        return $query->where('status', CampaignStatus::Active);
    }
}
