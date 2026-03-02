<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\ProfileRepositoryInterface;
use App\Enums\PayoutReviewStatus;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Collection;
use Prettus\Repository\Eloquent\BaseRepository;

class ProfileRepository extends BaseRepository implements ProfileRepositoryInterface
{
    public function model(): string
    {
        return UserProfile::class;
    }

    /**
     * Update or create a user profile.
     */
    public function updateOrCreateForUser(User $user, array $attributes): UserProfile
    {
        /** @var UserProfile */
        return $this->model->newQuery()->updateOrCreate(
            ['user_id' => $user->id],
            $attributes
        );
    }

    /**
     * Find profile by user ID.
     */
    public function findByUserId(int $userId): ?UserProfile
    {
        /** @var UserProfile|null */
        return $this->model->newQuery()
            ->where('user_id', $userId)
            ->first();
    }

    public function findByUserIdForScope(int $userId, ?array $scopeUserIds): ?UserProfile
    {
        $query = $this->model->newQuery()
            ->with(['user:id,name,email,parent_id'])
            ->where('user_id', $userId);

        if ($scopeUserIds !== null) {
            $query->whereIn('user_id', $scopeUserIds);
        }

        /** @var UserProfile|null */
        return $query->first();
    }

    public function listPendingForScope(?array $scopeUserIds, int $limit = 20): Collection
    {
        $query = $this->model->newQuery()
            ->with(['user:id,name,email,parent_id'])
            ->where('payout_review_status', PayoutReviewStatus::Pending->value)
            ->orderByDesc('updated_at')
            ->limit($limit);

        if ($scopeUserIds !== null) {
            $query->whereIn('user_id', $scopeUserIds);
        }

        /** @var Collection<int, UserProfile> */
        return $query->get();
    }
}
