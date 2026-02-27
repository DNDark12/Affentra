<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\PlatformConnection;
use App\Models\User;
use Prettus\Repository\Contracts\RepositoryInterface;

interface PlatformConnectionRepositoryInterface extends RepositoryInterface
{
    /**
     * Find a connection by user and platform (unique key).
     *
     * @return PlatformConnection|null
     */
    public function findByUserAndPlatform(int $userId, string $platform): ?PlatformConnection;

    /**
     * Find a connection visible to the actor.
     * Owner can access all connections, others only their own.
     */
    public function findVisibleById(int $connectionId, User $actor): ?PlatformConnection;

    /**
     * Get all active scheduled connections (for sync job).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PlatformConnection>
     */
    public function getActiveScheduled();
}
