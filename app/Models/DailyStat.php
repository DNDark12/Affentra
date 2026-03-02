<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Campaign;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyStat extends Model
{
    use HasFactory;
    /**
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'platform',
        'user_id',
        'campaign_id',
        'tracking_link_id',
        'clicks',
        'unique_clicks',
        'valid_clicks',
        'bot_clicks',
        'orders',
        'approved',
        'commission',
        'owner_id',
        'leader_id',
        'ctv_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date'          => 'date',
            'clicks'        => 'integer',
            'unique_clicks' => 'integer',
            'valid_clicks'  => 'integer',
            'bot_clicks'    => 'integer',
            'orders'        => 'integer',
            'approved'      => 'integer',
            'commission'    => 'decimal:2',
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
}
