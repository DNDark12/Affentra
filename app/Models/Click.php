<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Click extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tracking_link_id',
        'connection_id',
        'sub_id',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'ip',
        'user_agent',
        'referer',
        'referer_domain',
        'device_type',
        'fingerprint_hash',
        'hash_version',
        'is_bot',
        'bot_reason',
        'owner_id',
        'leader_id',
        'partner_user_id',
        'attribution_status',
        'source_meta',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'tracking_link_id' => 'integer',
            'owner_id' => 'integer',
            'leader_id' => 'integer',
            'partner_user_id' => 'integer',
            'hash_version' => 'integer',
            'is_bot' => 'boolean',
            'source_meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function trackingLink(): BelongsTo
    {
        return $this->belongsTo(TrackingLink::class);
    }

    public function platformConnection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class, 'connection_id');
    }
}
