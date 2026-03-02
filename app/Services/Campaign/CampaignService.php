<?php

declare(strict_types=1);

namespace App\Services\Campaign;

use App\Contracts\Repositories\CampaignRepositoryInterface;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Scope\ScopeResolver;
use Illuminate\Pagination\LengthAwarePaginator;

class CampaignService
{
    public function __construct(
        private readonly CampaignRepositoryInterface $campaignRepository,
        private readonly ScopeResolver $scopeResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForUser(User $user, array $filters = []): LengthAwarePaginator
    {
        $scopeUserIds = $this->scopeResolver->resolveVisibleUserIds($user);

        return $this->campaignRepository->paginateForScope($scopeUserIds, $filters, 15);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *   total_campaigns:int,
     *   active_campaigns:int,
     *   total_impressions:int,
     *   total_clicks:int
     * }
     */
    public function globalStatsForUser(User $user, array $filters = []): array
    {
        $scopeUserIds = $this->scopeResolver->resolveVisibleUserIds($user);

        return $this->campaignRepository->getGlobalStatsForScope($scopeUserIds, $filters);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(User $user, array $validated): Campaign
    {
        $validated['user_id'] = $user->id;
        $validated['status'] = CampaignStatus::Active;

        return $this->campaignRepository->createCampaign($validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(Campaign $campaign, array $validated): Campaign
    {
        return $this->campaignRepository->updateCampaign($campaign, $validated);
    }
}
