<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\PlatformConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRun extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'platform_connection_id',
        'type',
        'integration',
        'status',
        'started_at',
        'finished_at',
        'records_fetched',
        'records_upserted',
        'records_failed',
        'error_message',
        'error_log_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at'        => 'datetime',
            'finished_at'       => 'datetime',
            'records_fetched'   => 'integer',
            'records_upserted'  => 'integer',
            'records_failed'    => 'integer',
        ];
    }

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function platformConnection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return str_starts_with($this->status ?? '', 'failed');
    }

    public function isSkipped(): bool
    {
        return str_starts_with($this->status ?? '', 'skipped');
    }

    public function isRateLimited(): bool
    {
        return $this->status === 'rate_limited';
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }
}
