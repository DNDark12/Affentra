<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Contracts\Repositories\ProfileRepositoryInterface;
use App\Enums\PayoutReviewStatus;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Audit\AuditLogger;
use App\Services\Scope\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PayoutApprovalService
{
    public function __construct(
        private readonly ProfileRepositoryInterface $profileRepository,
        private readonly ScopeResolver $scopeResolver,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{
     *   can_review:bool,
     *   items:list<array{
     *     user_id:int,
     *     name:string,
     *     email:string,
     *     bank_name:string|null,
     *     bank_account_name:string|null,
     *     payout_review_status:string,
     *     updated_at:string|null
     *   }>
     * }
     */
    public function queueForReviewer(User $reviewer, int $limit = 10): array
    {
        $canReview = $this->canReview($reviewer);
        if (! $canReview) {
            return [
                'can_review' => false,
                'items' => [],
            ];
        }

        $scopeUserIds = $this->scopeResolver->resolveVisibleUserIds($reviewer);
        $profiles = $this->profileRepository->listPendingForScope($scopeUserIds, $limit);

        $items = $profiles
            ->filter(static fn (UserProfile $profile): bool => $profile->user_id !== $reviewer->id)
            ->map(static function (UserProfile $profile): array {
            return [
                'user_id' => (int) $profile->user_id,
                'name' => (string) ($profile->user?->name ?? 'Unknown'),
                'email' => (string) ($profile->user?->email ?? ''),
                'bank_name' => $profile->bank_name,
                'bank_account_name' => $profile->bank_account_name,
                'payout_review_status' => ($profile->payout_review_status instanceof PayoutReviewStatus)
                    ? $profile->payout_review_status->value
                    : (string) ($profile->payout_review_status ?? PayoutReviewStatus::Pending->value),
                'updated_at' => $profile->updated_at?->toIso8601String(),
            ];
            })
            ->values()
            ->all();

        return [
            'can_review' => true,
            'items' => $items,
        ];
    }

    /**
     * @return array{status:string,user_id:int}
     */
    public function approve(User $reviewer, int $targetUserId): array
    {
        $profile = $this->resolvePendingProfileOrFail($reviewer, $targetUserId);

        $previousState = $this->snapshot($profile);

        DB::transaction(function () use ($profile, $reviewer, $previousState): void {
            $profile->fill([
                'is_payout_ready' => true,
                'payout_review_status' => PayoutReviewStatus::Approved,
                'payout_reviewed_by' => $reviewer->id,
                'payout_reviewed_at' => now(),
                'payout_reject_reason' => null,
            ]);
            $profile->save();

            $this->auditLogger->log(
                actor: $reviewer,
                action: 'payout_profile.approve',
                target: $profile,
                previousState: $previousState,
                newState: $this->snapshot($profile),
                reason: null,
            );
        });

        return [
            'status' => PayoutReviewStatus::Approved->value,
            'user_id' => $profile->user_id,
        ];
    }

    /**
     * @return array{status:string,user_id:int}
     */
    public function reject(
        User $reviewer,
        int $targetUserId,
        string $reason,
    ): array {
        $profile = $this->resolvePendingProfileOrFail($reviewer, $targetUserId);
        $previousState = $this->snapshot($profile);

        DB::transaction(function () use ($profile, $reviewer, $reason, $previousState): void {
            $profile->fill([
                'is_payout_ready' => false,
                'payout_review_status' => PayoutReviewStatus::Rejected,
                'payout_reviewed_by' => $reviewer->id,
                'payout_reviewed_at' => now(),
                'payout_reject_reason' => $reason,
            ]);
            $profile->save();

            $this->auditLogger->log(
                actor: $reviewer,
                action: 'payout_profile.reject',
                target: $profile,
                previousState: $previousState,
                newState: $this->snapshot($profile),
                reason: $reason,
            );
        });

        return [
            'status' => PayoutReviewStatus::Rejected->value,
            'user_id' => $profile->user_id,
        ];
    }

    private function resolvePendingProfileOrFail(User $reviewer, int $targetUserId): UserProfile
    {
        if (! $this->canReview($reviewer)) {
            throw new AuthorizationException('Forbidden');
        }

        $scopeUserIds = $this->scopeResolver->resolveVisibleUserIds($reviewer);
        $profile = $this->profileRepository->findByUserIdForScope($targetUserId, $scopeUserIds);

        if ($profile === null || $profile->user_id === $reviewer->id) {
            throw new NotFoundHttpException('Not found.');
        }

        $status = $profile->payout_review_status instanceof PayoutReviewStatus
            ? $profile->payout_review_status
            : (PayoutReviewStatus::tryFrom((string) $profile->payout_review_status) ?? PayoutReviewStatus::Pending);

        if ($status !== PayoutReviewStatus::Pending) {
            throw new \DomainException('Payout profile is not pending review.');
        }

        return $profile;
    }

    private function canReview(User $reviewer): bool
    {
        return $reviewer->isOwner() || $reviewer->isLeader();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(UserProfile $profile): array
    {
        $status = $profile->payout_review_status instanceof PayoutReviewStatus
            ? $profile->payout_review_status->value
            : (string) ($profile->payout_review_status ?? PayoutReviewStatus::Pending->value);

        return [
            'is_payout_ready' => (bool) $profile->is_payout_ready,
            'payout_review_status' => $status,
            'payout_reviewed_by' => $profile->payout_reviewed_by,
            'payout_reviewed_at' => $profile->payout_reviewed_at?->toIso8601String(),
            'payout_reject_reason' => $profile->payout_reject_reason,
        ];
    }

}
