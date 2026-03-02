<?php

declare(strict_types=1);

namespace App\Services\Scope;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;

class ScopeResolver
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    /**
     * Resolve visible user IDs for scoped queries.
     *
     * Null means unscoped (owner/global query).
     *
     * @return list<int>|null
     */
    public function resolveVisibleUserIds(User $auth): ?array
    {
        if ($auth->isOwner()) {
            return null;
        }

        if ($auth->isLeader()) {
            return array_values(array_unique(array_merge(
                [$auth->id],
                $this->userRepository->getDescendantIds($auth->id),
            )));
        }

        return [$auth->id];
    }
}

