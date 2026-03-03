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
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'prompt_attributes' => 'array',
        'output_payload'    => 'array',
        'force_new_seed'    => 'boolean',
        'from_cache'        => 'boolean',
        'tokens_prompt'     => 'integer',
        'tokens_completion' => 'integer',
        'cost_amount'       => 'decimal:6',
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
     * Check if this generation is terminal (i.e. not still running).
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'canceled'], true);
    }
}
