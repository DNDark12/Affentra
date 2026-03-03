<?php

declare(strict_types=1);

namespace App\Models;

use App\DataTransferObjects\CapabilitySet;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class PlatformConnection extends Model
{
    use HasFactory;
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'label',
        'platform',
        'method',
        'app_id',
        'cookie_header',
        'cookie_user_agent',
        'cookie_source',
        'cookie_validated_at',
        'consent_acknowledged_at',
        'app_secret',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'status',
        'sync_mode',
        'sync_interval',
        'sync_time',
        'capabilities',
        'backfill_days_override',
        'last_sync_at',
        'last_campaign_sync_at',
        'last_sync_status',
        'last_error',
        'last_error_at',
    ];

    /**
     * Sensitive credential fields — hidden from serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'app_secret',
        'access_token',
        'refresh_token',
        'cookie_header',
        'cookie_user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_expires_at'          => 'datetime',
            'last_sync_at'              => 'datetime',
            'last_campaign_sync_at'     => 'datetime',
            'last_error_at'             => 'datetime',
            'cookie_validated_at'       => 'datetime',
            'consent_acknowledged_at'   => 'datetime',
            'capabilities'              => 'array',
            'backfill_days_override'    => 'integer',
            'cookie_header'             => 'encrypted',
            'cookie_user_agent'         => 'encrypted',
        ];
    }

    /**
     * Get the typed CapabilitySet from the JSON snapshot.
     */
    public function getCapabilitySet(): CapabilitySet
    {
        return CapabilitySet::fromArray($this->capabilities);
    }

    // ─── Encrypted accessors ───────────────────────────────────────────────────

    /**
     * Encrypt app_secret before storing.
     */
    public function setAppSecretAttribute(?string $value): void
    {
        $this->attributes['app_secret'] = $value !== null
            ? Crypt::encryptString($value)
            : null;
    }

    /**
     * Decrypt app_secret when reading.
     */
    public function getAppSecretAttribute(?string $value): ?string
    {
        return $value !== null ? Crypt::decryptString($value) : null;
    }

    /**
     * Encrypt access_token before storing.
     */
    public function setAccessTokenAttribute(?string $value): void
    {
        $this->attributes['access_token'] = $value !== null
            ? Crypt::encryptString($value)
            : null;
    }

    /**
     * Decrypt access_token when reading.
     */
    public function getAccessTokenAttribute(?string $value): ?string
    {
        return $value !== null ? Crypt::decryptString($value) : null;
    }

    /**
     * Encrypt refresh_token before storing.
     */
    public function setRefreshTokenAttribute(?string $value): void
    {
        $this->attributes['refresh_token'] = $value !== null
            ? Crypt::encryptString($value)
            : null;
    }

    /**
     * Decrypt refresh_token when reading.
     */
    public function getRefreshTokenAttribute(?string $value): ?string
    {
        return $value !== null ? Crypt::decryptString($value) : null;
    }

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at !== null
            && $this->token_expires_at->isPast();
    }

    public function isScheduled(): bool
    {
        return $this->sync_mode === 'scheduled';
    }

    public function getSyncIntervalMinutes(): int
    {
        return match ($this->sync_interval) {
            '15m' => 15,
            '1h' => 60,
            '3h' => 180,
            '8h' => 480,
            'daily' => 1440,
            default => 15,
        };
    }
}
