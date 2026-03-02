<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\CampaignRepositoryInterface;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Builder;
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
            ->orderByDesc('synced_at')
            ->latest();

        if ($scopeUserIds !== null) {
            $query->whereIn('user_id', $scopeUserIds);
        }

        $this->applyFilters($query, $filters);

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

    public function getGlobalStatsForScope(?array $scopeUserIds, array $filters = []): array
    {
        $query = $this->model->newQuery();

        if ($scopeUserIds !== null) {
            $query->whereIn('user_id', $scopeUserIds);
        }

        $this->applyFilters($query, $filters);

        $row = $query
            ->selectRaw('COUNT(*) as total_campaigns')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as active_campaigns',
                [CampaignStatus::Active->value],
            )
            ->selectRaw('COALESCE(SUM(impressions), 0) as total_impressions')
            ->selectRaw('COALESCE(SUM(clicks), 0) as total_clicks')
            ->first();

        return [
            'total_campaigns' => (int) ($row?->total_campaigns ?? 0),
            'active_campaigns' => (int) ($row?->active_campaigns ?? 0),
            'total_impressions' => (int) ($row?->total_impressions ?? 0),
            'total_clicks' => (int) ($row?->total_clicks ?? 0),
        ];
    }

    /**
     * @param  Builder<Campaign>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['search'])) {
            $term = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($subQuery) use ($term): void {
                $subQuery->where('name', 'LIKE', $term)
                    ->orWhere('external_id', 'LIKE', $term);
            });
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('date_start', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('date_end', '<=', (string) $filters['date_to']);
        }
    }
}
