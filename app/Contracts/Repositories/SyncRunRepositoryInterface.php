<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\SyncRun;
use Prettus\Repository\Contracts\RepositoryInterface;

interface SyncRunRepositoryInterface extends RepositoryInterface
{
    /**
     * Get the latest sync run for a connection.
     *
     * @return SyncRun|null
     */
    public function getLatestForConnection(int $platformConnectionId): ?SyncRun;
}
