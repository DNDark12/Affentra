<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\AffiliatePayoutRepositoryInterface;
use App\Models\AffiliatePayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Prettus\Repository\Eloquent\BaseRepository;

class AffiliatePayoutRepository extends BaseRepository implements AffiliatePayoutRepositoryInterface
{
    public function model(): string
    {
        return AffiliatePayout::class;
    }

    public function paginateForScope(
        ?array $scopeUserIds,
        array $filters = [],
        int $perPage = 20,
        string $pageName = 'payouts_page',
    ): LengthAwarePaginator {
        $query = $this->model->newQuery()->latest('payout_at');

        if ($scopeUserIds !== null) {
            $query->whereIn('user_id', $scopeUserIds);
        }

        $this->applyDateFilter($query, $filters);

        if (! empty($filters['payout_status'])) {
            $query->where('status', (string) $filters['payout_status']);
        }

        return $query
            ->paginate($perPage, ['*'], $pageName)
            ->withQueryString();
    }

    public function sumAmountByStatuses(?array $scopeUserIds, array $statuses, array $filters = []): float
    {
        $query = $this->model->newQuery();

        if ($scopeUserIds !== null) {
            $query->whereIn('user_id', $scopeUserIds);
        }

        $this->applyDateFilter($query, $filters);

        $normalizedStatuses = array_map(static fn (string $status): string => mb_strtolower(trim($status)), $statuses);
        if ($normalizedStatuses !== []) {
            $query->whereIn('status', $normalizedStatuses);
        }

        return (float) $query->sum('amount');
    }

    /**
     * @param  Builder<AffiliatePayout>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDateFilter(Builder $query, array $filters): void
    {
        if (! empty($filters['date_from'])) {
            $query->where('payout_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('payout_at', '<=', $filters['date_to']);
        }
    }
}

