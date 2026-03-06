<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Campaign;
use App\Models\PlatformConnection;
use App\Models\TrackingLink;
use App\Models\UserIdentity;
use App\Models\UserProfile;
use App\Models\AlertRule;
use App\Models\AlertIncident;
use App\Models\AlertMessageTemplate;
use App\Models\UserTelegramConfig;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'name',
        'email',
        'avatar',
        'phone',
        'password',
        'role',
        'status',
        'depth',
        'path',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'depth'             => 'integer',
            'role'              => UserRole::class,
            'status'            => UserStatus::class,
        ];
    }

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function trackingLinks(): HasMany
    {
        return $this->hasMany(TrackingLink::class);
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(\App\Models\DailyStat::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function platformConnections(): HasMany
    {
        return $this->hasMany(PlatformConnection::class);
    }

    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }

    public function alertIncidents(): HasMany
    {
        return $this->hasMany(AlertIncident::class);
    }

    public function alertMessageTemplates(): HasMany
    {
        return $this->hasMany(AlertMessageTemplate::class);
    }

    public function telegramConfig(): HasOne
    {
        return $this->hasOne(UserTelegramConfig::class);
    }

    public function aiProviderSettings(): HasMany
    {
        return $this->hasMany(AiProviderSetting::class);
    }


    // ─── Scopes ───────────────────────────────────────────────────────────────

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeActive($query)
    {
        return $query->where('status', UserStatus::Active);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeByRole($query, UserRole $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Descendants via materialized path (faster for read-heavy hierarchies).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeDescendantsOf($query, self $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('path', 'LIKE', $user->id . '/%')
              ->orWhere('path', 'LIKE', '%/' . $user->id . '/%')
              ->orWhere('parent_id', $user->id);
        });
    }

    // ─── Role Helpers ──────────────────────────────────────────────────────────

    public function isOwner(): bool
    {
        return $this->role === UserRole::Owner;
    }

    public function isLeader(): bool
    {
        return $this->role === UserRole::Leader;
    }

    public function isPartner(): bool
    {
        if ($this->role instanceof UserRole) {
            return $this->role->isPartnerRole();
        }

        return (string) $this->role === UserRole::Partner->value;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Get all descendant IDs using materialized path.
     * Uses prefix-based LIKE to leverage the B-tree index on `path`.
     *
     * @return list<int>
     */
    public function getDescendantIds(): array
    {
        // The user's full path serves as the prefix anchor.
        // E.g., if this user's path is "1/3", descendants have paths like "1/3/7", "1/3/7/12".
        $prefix = ($this->path ? $this->path : (string) $this->id);
        $id = $this->id;

        return self::query()
            ->where('id', '!=', $id)
            ->where(function ($q) use ($prefix, $id) {
                // All descendants via path structure: path LIKE "prefix/%"
                $q->where('path', 'LIKE', $prefix . '/%')
                  // Fallback for safety: parent_id = user's ID
                  ->orWhere('parent_id', $id);
            })
            ->pluck('id')
            ->all();
    }

    /**
     * Build the materialized path for this user.
     */
    public function buildPath(): string
    {
        if ($this->parent_id === null) {
            return (string) $this->id;
        }

        $parentPath = $this->parent?->path ?? (string) $this->parent_id;

        return $parentPath . '/' . $this->id;
    }
}
