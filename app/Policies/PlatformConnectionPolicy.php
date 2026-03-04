<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PlatformConnectionPolicy
{
    use HandlesAuthorization;

    /**
     * Index scoping is handled by IntegrationController (queries by user_id).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PlatformConnection $connection): bool
    {
        return $this->isOwnerOrInTeam($user, $connection);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PlatformConnection $connection): bool
    {
        return $this->isOwnerOrInTeam($user, $connection);
    }

    public function delete(User $user, PlatformConnection $connection): bool
    {
        return $this->isOwnerOrInTeam($user, $connection);
    }

    /**
     * TODO: Expand for multi-tenant team scope when roles mature.
     */
    private function isOwnerOrInTeam(User $user, PlatformConnection $connection): bool
    {
        if ((int) $connection->user_id === $user->id) {
            return true;
        }

        if ($user->isOwner()) {
            return true;
        }

        if ($user->isLeader()) {
            return in_array($connection->user_id, $user->getDescendantIds(), true);
        }

        return false;
    }
}
