<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Prettus\Repository\Contracts\RepositoryInterface;

interface AffiliatePayoutRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  list<int>|null  $scopeUserIds
     * @param  array<string, mixed>  $filters
     */
    public function paginateForScope(
        ?array $scopeUserIds,
        array $filters = [],
        int $perPage = 20,
        string $pageName = 'payouts_page',
    ): LengthAwarePaginator;

    /**
     * @param  list<int>|null  $scopeUserIds
     * @param  list<string>  $statuses
     * @param  array<string, mixed>  $filters
     */
    public function sumAmountByStatuses(?array $scopeUserIds, array $statuses, array $filters = []): float;
}

