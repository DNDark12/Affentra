<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Click extends Model
{
    /**
     * No updated_at — insert-only log table.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tracking_link_id',
        'sub_id',
        'ip',
        'user_agent',
        'referer',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function trackingLink(): BelongsTo
    {
        return $this->belongsTo(TrackingLink::class);
    }
}
