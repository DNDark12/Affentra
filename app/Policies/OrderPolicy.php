<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class OrderPolicy
{
    use HandlesAuthorization;

    /**
     * Index scoping is handled by OrderService::listForUser().
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Order $order): bool
    {
        return $this->isOwnerOrInTeam($user, $order);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Order $order): bool
    {
        return $this->isOwnerOrInTeam($user, $order);
    }

    public function delete(User $user, Order $order): bool
    {
        return $this->isOwnerOrInTeam($user, $order);
    }

    /**
     * Check direct ownership or team hierarchy.
     * TODO: Expand for multi-tenant team scope when roles mature.
     */
    private function isOwnerOrInTeam(User $user, Order $order): bool
    {
        if ((int) $order->user_id === $user->id) {
            return true;
        }

        if ($user->isOwner()) {
            return true;
        }

        if ($user->isLeader()) {
            return in_array($order->user_id, $user->getDescendantIds(), true);
        }

        return false;
    }
}
