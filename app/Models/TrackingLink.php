<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\LinkStatusCast;
use App\Enums\LinkStatus;
use App\Enums\Platform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrackingLink extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'platform_connection_id',
        'campaign_id',
        'short_code',
        'destination_url',
        'platform',
        'tags',
        'meta',
        'channel',
        'source',
        'sub_id',
        'status',
        'clicks_count',
        'orders_count',
        'product_name',
        'product_price',
        'product_price_value',
        'product_image_urls',
        'product_last_scraped_at',
        'product_scrape_confidence',
        'product_scrape_source',
        'product_scrape_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform_connection_id'    => 'integer',
            'tags'               => 'array',
            'meta'               => 'array',
            'product_image_urls'      => 'array',
            'product_last_scraped_at' => 'datetime',
            'product_scrape_confidence' => 'float',
            'clicks_count'            => 'integer',
            'orders_count'            => 'integer',
            'status'                  => LinkStatusCast::class,
            'platform'                => Platform::class,
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

    public function platformConnection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class, 'platform_connection_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(Click::class);
    }

    public function contentGenerations(): HasMany
    {
        return $this->hasMany(ContentGeneration::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeActive($query)
    {
        return $query->where('status', LinkStatus::Active);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
