<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    /**
     * Owner can see all users; Leader can see direct Partners; Partner sees only self.
     */
    public function viewAny(User $auth): bool
    {
        return $auth->role === UserRole::Owner || $auth->role === UserRole::Leader;
    }

    /**
     * Owner sees everyone; Leader sees own partners; Partner never.
     */
    public function view(User $auth, User $target): bool
    {
        if ($auth->isOwner()) {
            return true;
        }

        if ($auth->isLeader()) {
            if ($target->id === $auth->id) {
                return true;
            }

            return in_array($target->id, $auth->getDescendantIds(), true);
        }

        return $auth->id === $target->id;
    }

    /**
     * Only owner or leader can create new users (partners).
     */
    public function create(User $auth): bool
    {
        return $auth->role === UserRole::Owner || $auth->role === UserRole::Leader;
    }

    /**
     * Owner can update any; Leader can update own partners only; Partner can update self.
     */
    public function update(User $auth, User $target): bool
    {
        if ($auth->isOwner()) {
            return true;
        }

        if ($auth->isLeader()) {
            return in_array($target->id, $auth->getDescendantIds(), true);
        }

        return $auth->id === $target->id;
    }

    /**
     * Only owner can delete.
     */
    public function delete(User $auth, User $target): bool
    {
        return $auth->isOwner() && $auth->id !== $target->id;
    }

    /**
     * Only owner can impersonate/promote.
     */
    public function manageRole(User $auth): bool
    {
        return $auth->isOwner();
    }
}
