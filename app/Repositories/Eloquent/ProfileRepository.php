<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\ProfileRepositoryInterface;
use App\Models\User;
use App\Models\UserProfile;
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
}
