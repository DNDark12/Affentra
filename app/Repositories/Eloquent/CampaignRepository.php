<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\CampaignRepositoryInterface;
use App\Models\Campaign;
use Illuminate\Pagination\LengthAwarePaginator;
use Prettus\Repository\Eloquent\BaseRepository;

class CampaignRepository extends BaseRepository implements CampaignRepositoryInterface
{
    public function model(): string
    {
        return Campaign::class;
    }

    public function paginateForScope(?array $scopeUserIds, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->withCount('trackingLinks as links_count')
            ->latest();

        if ($scopeUserIds !== null) {
            $query->whereIn('user_id', $scopeUserIds);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $term = '%' . trim((string) $filters['search']) . '%';
            $query->where('name', 'LIKE', $term);
        }

        return $query->paginate($perPage);
    }

    public function findByIdForScope(int $id, ?array $scopeUserIds): ?Campaign
    {
        $query = $this->model->newQuery()->where('id', $id);

        if ($scopeUserIds !== null) {
            $query->whereIn('user_id', $scopeUserIds);
        }

        /** @var Campaign|null */
        return $query->first();
    }

    public function createCampaign(array $attributes): Campaign
    {
        /** @var Campaign */
        return $this->model->newQuery()->create($attributes);
    }

    public function updateCampaign(Campaign $campaign, array $attributes): Campaign
    {
        $campaign->update($attributes);

        return $campaign->fresh();
    }
}
