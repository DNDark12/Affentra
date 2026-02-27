<?php

declare(strict_types=1);

namespace App\Services\Campaign;

use App\Contracts\Repositories\CampaignRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class CampaignService
{
    public function __construct(
        private readonly CampaignRepositoryInterface $campaignRepository,
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForUser(User $user, array $filters = []): LengthAwarePaginator
    {
        $scopeUserIds = $this->resolveScopeUserIds($user);

        return $this->campaignRepository->paginateForScope($scopeUserIds, $filters, 15);
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

    /**
     * @return list<int>|null
     */
    private function resolveScopeUserIds(User $user): ?array
    {
        if ($user->isOwner()) {
            return null;
        }

        if ($user->isLeader()) {
            return array_merge([$user->id], $this->userRepository->getDescendantIds($user->id));
        }

        return [$user->id];
    }
}
