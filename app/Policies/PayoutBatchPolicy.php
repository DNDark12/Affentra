<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PayoutBatchStatus;
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
     * Only Owner/Leader roles can manage batches (Partner blocked by PayoutBatchService::assertCanManage).
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
        return ! $user->isPartner();
    }

    public function update(User $user, PayoutBatch $batch): bool
    {
        return $this->canAccessBatch($user, $batch);
    }

    /**
     * Finalize action — delegates to access check.
     */
    public function finalize(User $user, PayoutBatch $batch): bool
    {
        return $this->canAccessBatch($user, $batch);
    }

    /**
     * Export is allowed once the batch is finalized or exported.
     */
    public function export(User $user, PayoutBatch $batch): bool
    {
        if ($user->isPartner()) {
            return false;
        }

        if (! ($batch->status instanceof PayoutBatchStatus)) {
            return false;
        }

        return $batch->status->canExport() && $this->canAccessBatch($user, $batch);
    }

    /**
     * Only draft batches can be deleted.
     */
    public function delete(User $user, PayoutBatch $batch): bool
    {
        if ($user->isPartner()) {
            return false;
        }

        return $this->canAccessBatch($user, $batch);
    }

    /**
     * PayoutBatch uses `created_by` instead of `user_id`.
     * Batch access is scoped via ScopeResolver in the service layer (assertBatchVisible).
     * Policy acts as a Route Model Binding guard (fast fail before service layer).
     *
     * Multi-tenant isolation: two Owner accounts in separate trees must NOT cross-access
     * each other's batches. Owners are restricted to batches they personally created.
     * Leaders are restricted to batches created by themselves or their descendant users.
     */
    private function canAccessBatch(User $user, PayoutBatch $batch): bool
    {
        if ($user->isPartner()) {
            return false;
        }

        // Owner can only access batches they themselves created (tenant isolation)
        if ($user->isOwner()) {
            return (int) $batch->created_by === $user->id;
        }

        // Creator can always access their own batch
        if ((int) $batch->created_by === $user->id) {
            return true;
        }

        // Leader can access batches created by their descendants
        if ($user->isLeader()) {
            return in_array($batch->created_by, $user->getDescendantIds(), true);
        }

        return false;
    }
}
