<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PayoutBatch;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PayoutBatchPolicy
{
    use HandlesAuthorization;

    /**
     * Index scoping is handled by PayoutBatchService::list() via ScopeResolver.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * PayoutBatch visibility is team-scoped via ScopeResolver.
     * Only Owner/Leader roles can manage batches (CTV blocked by PayoutBatchService::assertCanManage).
     */
    public function view(User $user, PayoutBatch $batch): bool
    {
        return $this->canAccessBatch($user, $batch);
    }

    /**
     * Only Owner/Leader can create batches.
     */
    public function create(User $user): bool
    {
        return ! $user->isCTV();
    }

    public function update(User $user, PayoutBatch $batch): bool
    {
        return $this->canAccessBatch($user, $batch);
    }

    /**
     * Finalize action — delegates to update check.
     */
    public function finalize(User $user, PayoutBatch $batch): bool
    {
        return $this->canAccessBatch($user, $batch);
    }

    public function delete(User $user, PayoutBatch $batch): bool
    {
        return $this->canAccessBatch($user, $batch);
    }

    /**
     * PayoutBatch uses `created_by` instead of `user_id`.
     * Batch access is scoped via ScopeResolver in the service layer (assertBatchVisible).
     * Policy acts as a secondary guard for Route Model Binding.
     *
     * TODO: Expand checks to match ScopeResolver logic for full parity.
     */
    private function canAccessBatch(User $user, PayoutBatch $batch): bool
    {
        // System owner can access all batches
        if ($user->isOwner()) {
            return true;
        }

        // Creator can always access their own batch
        if ((int) $batch->created_by === $user->id) {
            return true;
        }

        // Leader can access batches created by their descendants
        if ($user->isLeader()) {
            return in_array($batch->created_by, $user->getDescendantIds(), true);
        }

        // CTV cannot access batches (enforced by service layer too)
        return false;
    }
}
