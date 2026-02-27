<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\PlatformConnectionRepositoryInterface;
use App\Enums\PlatformConnectionStatus;
use App\Models\PlatformConnection;
use App\Models\User;
use Prettus\Repository\Eloquent\BaseRepository;

class PlatformConnectionRepository extends BaseRepository implements PlatformConnectionRepositoryInterface
{
    public function model(): string
    {
        return PlatformConnection::class;
    }

    public function findByUserAndPlatform(int $userId, string $platform): ?PlatformConnection
    {
        /** @var PlatformConnection|null */
        return $this->model->newQuery()
            ->where('user_id', $userId)
            ->where('platform', $platform)
            ->first();
    }

    public function findVisibleById(int $connectionId, User $actor): ?PlatformConnection
    {
        $query = $this->model->newQuery()->whereKey($connectionId);

        if (! $actor->isOwner()) {
            $query->where('user_id', $actor->id);
        }

        /** @var PlatformConnection|null */
        return $query->first();
    }

    public function getActiveScheduled()
    {
        return $this->model->newQuery()
            ->where('status', PlatformConnectionStatus::Active)
            ->where('sync_mode', 'scheduled')
            ->get();
    }
}
