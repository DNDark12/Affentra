<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayoutReviewStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'bank_code',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'tax_id',
        'is_payout_ready',
        'payout_review_status',
        'payout_reviewed_by',
        'payout_reviewed_at',
        'payout_reject_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bank_account_number' => 'encrypted',
            'tax_id' => 'encrypted',
            'is_payout_ready' => 'boolean',
            'payout_review_status' => PayoutReviewStatus::class,
            'payout_reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payout_reviewed_by');
    }
}
