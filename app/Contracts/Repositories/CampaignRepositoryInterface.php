<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Campaign;
use Illuminate\Pagination\LengthAwarePaginator;
use Prettus\Repository\Contracts\RepositoryInterface;

interface CampaignRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  list<int>|null  $scopeUserIds  Null means no user scope (owner).
     * @param  array<string, mixed>  $filters
     */
    public function paginateForScope(?array $scopeUserIds, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Find campaign by ID within scope.
     *
     * @param  list<int>|null  $scopeUserIds
     */
    public function findByIdForScope(int $id, ?array $scopeUserIds): ?Campaign;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createCampaign(array $attributes): Campaign;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateCampaign(Campaign $campaign, array $attributes): Campaign;

    /**
     * Aggregate totals across the full filtered scope (not paginated subset).
     *
     * @param  list<int>|null  $scopeUserIds
     * @param  array<string, mixed>  $filters
     * @return array{
     *   total_campaigns:int,
     *   active_campaigns:int,
     *   total_impressions:int,
     *   total_clicks:int
     * }
     */
    public function getGlobalStatsForScope(?array $scopeUserIds, array $filters = []): array;
}
