<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Collection;
use Prettus\Repository\Contracts\RepositoryInterface;

interface ProfileRepositoryInterface extends RepositoryInterface
{
    /**
     * Update or create a user profile.
     */
    public function updateOrCreateForUser(User $user, array $attributes): UserProfile;

    /**
     * Find profile by user ID.
     */
    public function findByUserId(int $userId): ?UserProfile;

    /**
     * Find a profile by user ID within reviewer scope.
     *
     * @param  list<int>|null  $scopeUserIds
     */
    public function findByUserIdForScope(int $userId, ?array $scopeUserIds): ?UserProfile;

    /**
     * @param  list<int>|null  $scopeUserIds
     * @return Collection<int, UserProfile>
     */
    public function listPendingForScope(?array $scopeUserIds, int $limit = 20): Collection;
}
