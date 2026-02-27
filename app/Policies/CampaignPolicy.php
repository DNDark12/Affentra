<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CampaignPolicy
{
    use HandlesAuthorization;

    /**
     * Everyone can view the campaign list (scoped in controller).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * A user can view a campaign if they own it or it belongs to their team.
     */
    public function view(User $user, Campaign $campaign): bool
    {
        return $this->isOwnerOrInTeam($user, $campaign);
    }

    /**
     * Only Owner and Leader roles can create campaigns.
     */
    public function create(User $user): bool
    {
        return $user->isOwner() || $user->isLeader();
    }

    /**
     * Can update only if the campaign belongs to the user or their team.
     */
    public function update(User $user, Campaign $campaign): bool
    {
        return $this->isOwnerOrInTeam($user, $campaign);
    }

    /**
     * Check if the user owns the campaign directly, or the campaign owner
     * is in their descendant tree (for Owner/Leader roles).
     */
    private function isOwnerOrInTeam(User $user, Campaign $campaign): bool
    {
        // Direct ownership
        if ($campaign->user_id === $user->id) {
            return true;
        }

        // System owner can manage all campaigns
        if ($user->isOwner()) {
            return true;
        }

        // Leader can manage campaigns of their CTVs
        if ($user->isLeader()) {
            $childIds = $user->getDescendantIds();

            return in_array($campaign->user_id, $childIds, true);
        }

        return false;
    }
}
