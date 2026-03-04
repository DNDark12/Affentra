<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentGeneration extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'tracking_link_id',
        'user_id',
        'type',
        'platform',
        'status',
        'prompt_template_id',
        'prompt_attributes',
        'prompt_hash',
        'force_new_seed',
        'from_cache',
        'output_payload',
        'ai_provider',
        'ai_model',
        'tokens_prompt',
        'tokens_completion',
        'cost_amount',
        'cost_currency',
        'error_code',
        'error_message',
        'provider_task_id',
        'provider_status',
        'poll_attempts',
        'provider_completed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'prompt_attributes'      => 'array',
        'output_payload'         => 'array',
        'force_new_seed'         => 'boolean',
        'from_cache'             => 'boolean',
        'tokens_prompt'          => 'integer',
        'tokens_completion'      => 'integer',
        'cost_amount'            => 'decimal:6',
        'poll_attempts'          => 'integer',
        'provider_completed_at'  => 'datetime',
    ];

    public function trackingLink(): BelongsTo
    {
        return $this->belongsTo(TrackingLink::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if this generation is terminal (i.e. not still running/queued).
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'canceled'], true);
    }

    /**
     * Check if this generation uses an async provider (e.g. Seedance).
     */
    public function isAsync(): bool
    {
        return $this->provider_task_id !== null;
    }
}
