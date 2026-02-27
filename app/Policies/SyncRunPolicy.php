<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SyncRun;
use App\Models\User;
use App\Contracts\Repositories\UserRepositoryInterface;

class SyncRunPolicy
{
    public function __construct(private readonly UserRepositoryInterface $userRepository) {}

    /**
     * Determine whether the user can view the sync run.
     */
    public function view(User $user, SyncRun $syncRun): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        if ($user->id === $syncRun->user_id) {
            return true;
        }

        if ($user->isLeader() && $syncRun->user_id) {
            $descendants = $this->userRepository->getDescendantIds($user->id);
            return in_array($syncRun->user_id, $descendants, true);
        }

        return false;
    }
}
