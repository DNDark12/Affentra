<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\SyncRunRepositoryInterface;
use App\Models\SyncRun;
use Prettus\Repository\Eloquent\BaseRepository;

class SyncRunRepository extends BaseRepository implements SyncRunRepositoryInterface
{
    public function model(): string
    {
        return SyncRun::class;
    }

    public function getLatestForConnection(int $platformConnectionId): ?SyncRun
    {
        /** @var SyncRun|null */
        return $this->model->newQuery()
            ->where('platform_connection_id', $platformConnectionId)
            ->latest('started_at')
            ->first();
    }
}
