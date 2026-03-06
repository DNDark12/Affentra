<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TrackingLinkPolicy
{
    use HandlesAuthorization;

    /**
     * Any authenticated user can view their own links (scoped in repository).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Only the link owner (or their team leader/owner) can view a specific link.
     */
    public function view(User $user, TrackingLink $link): bool
    {
        return $this->isOwnerOrInTeam($user, $link);
    }

    /**
     * Any authenticated user can create links for themselves.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Only the link owner (or their team leader/owner) can update.
     */
    public function update(User $user, TrackingLink $link): bool
    {
        return $this->isOwnerOrInTeam($user, $link);
    }

    /**
     * Only the link owner (or their team leader/owner) can archive.
     */
    public function archive(User $user, TrackingLink $link): bool
    {
        return $this->isOwnerOrInTeam($user, $link);
    }

    /**
     * Check if the user owns the link directly, or is a leader/owner with
     * the link owner in their descendant tree.
     */
    private function isOwnerOrInTeam(User $user, TrackingLink $link): bool
    {
        // Direct ownership
        if ($link->user_id === $user->id) {
            return true;
        }

        // System owner can manage all links
        if ($user->isOwner()) {
            return true;
        }

        // Leader can manage links of their direct children (partners)
        if ($user->isLeader()) {
            $childIds = $user->getDescendantIds();

            return in_array($link->user_id, $childIds, true);
        }

        return false;
    }
}
