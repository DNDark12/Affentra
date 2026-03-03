<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class AiProviderSetting extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'provider_key',
        'credentials_encrypted',
        'default_model',
        'status',
        'capabilities',
        'label',
        'token_quota_per_day',
        'last_test_status',
        'last_test_error',
        'last_tested_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'capabilities'        => 'array',
        'token_quota_per_day' => 'integer',
        'last_tested_at'      => 'datetime',
    ];

    /** Hide raw encrypted blob from serialization. */
    protected $hidden = ['credentials_encrypted'];

    // ── Credential helpers ────────────────────────────────────────────────────

    /**
     * Store credentials as encrypted JSON.
     *
     * @param  array{api_key?: string, base_url?: string, project_id?: string} $credentials
     */
    public function setCredentials(array $credentials): void
    {
        // Remove empty values before storing
        $clean = array_filter($credentials, fn ($v) => $v !== null && $v !== '');
        $this->credentials_encrypted = Crypt::encryptString(json_encode($clean));
        $this->save();
    }

    /**
     * Decrypt and return credential bag.
     *
     * @return array{api_key?: string, base_url?: string, project_id?: string}
     */
    public function getCredentials(): array
    {
        if (! $this->credentials_encrypted) {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($this->credentials_encrypted), true) ?? [];
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Return decrypted api_key (or null).
     */
    public function apiKey(): ?string
    {
        return $this->getCredentials()['api_key'] ?? null;
    }

    /**
     * Return base_url for self-hosted providers (or null).
     */
    public function baseUrl(): ?string
    {
        return $this->getCredentials()['base_url'] ?? null;
    }

    /**
     * Check if provider is fully configured (credentials present).
     */
    public function isConfigured(): bool
    {
        $creds = $this->getCredentials();
        return ! empty($creds['api_key']) || ! empty($creds['base_url']);
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
