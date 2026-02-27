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
     * Owner can see all users; Leader can see direct CTVs; CTV sees only self.
     */
    public function viewAny(User $auth): bool
    {
        return $auth->role === UserRole::Owner || $auth->role === UserRole::Leader;
    }

    /**
     * Owner sees everyone; Leader sees own CTVs; CTV never.
     */
    public function view(User $auth, User $target): bool
    {
        if ($auth->isOwner()) {
            return true;
        }

        if ($auth->isLeader()) {
            return $target->parent_id === $auth->id || $target->id === $auth->id;
        }

        return $auth->id === $target->id;
    }

    /**
     * Only owner or leader can create new users (CTVs).
     */
    public function create(User $auth): bool
    {
        return $auth->role === UserRole::Owner || $auth->role === UserRole::Leader;
    }

    /**
     * Owner can update any; Leader can update own CTVs only; CTV can update self.
     */
    public function update(User $auth, User $target): bool
    {
        if ($auth->isOwner()) {
            return true;
        }

        if ($auth->isLeader()) {
            return $target->parent_id === $auth->id;
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
