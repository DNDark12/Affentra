<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AlertRule;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AlertRulePolicy
{
    use HandlesAuthorization;

    /**
     * Index scoping is handled by AlertService::indexData().
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AlertRule $rule): bool
    {
        return $this->isOwnerOrInTeam($user, $rule);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, AlertRule $rule): bool
    {
        return $this->isOwnerOrInTeam($user, $rule);
    }

    public function delete(User $user, AlertRule $rule): bool
    {
        return $this->isOwnerOrInTeam($user, $rule);
    }

    /**
     * TODO: Expand for multi-tenant team scope when roles mature.
     */
    private function isOwnerOrInTeam(User $user, AlertRule $rule): bool
    {
        if ((int) $rule->user_id === $user->id) {
            return true;
        }

        if ($user->isOwner()) {
            return true;
        }

        if ($user->isLeader()) {
            return in_array($rule->user_id, $user->getDescendantIds(), true);
        }

        return false;
    }
}
