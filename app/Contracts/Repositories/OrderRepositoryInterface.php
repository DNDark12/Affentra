<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Prettus\Repository\Contracts\RepositoryInterface;

interface OrderRepositoryInterface extends RepositoryInterface
{
    /**
     * Paginated order list for a set of user IDs with filters.
     *
     * @param  list<int>            $userIds
     * @param  array<string, mixed> $filters  Keys: status, period, search, platform
     */
    public function listForUsers(array $userIds, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * Bulk upsert orders from CSV import. Keyed on (platform, order_code).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{upserted: int, failed: int}
     */
    public function upsertFromImport(array $rows): array;

    /**
     * Get aggregate stats for a set of user IDs within a period.
     *
     * @param  list<int>  $userIds
     * @return array{total_orders: int, approved_orders: int, total_commission: float, total_amount: float}
     */
    public function getStatsByUsers(array $userIds, string $period = '30days'): array;
}
