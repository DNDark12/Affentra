<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\User;
use App\Models\UserProfile;
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
}
