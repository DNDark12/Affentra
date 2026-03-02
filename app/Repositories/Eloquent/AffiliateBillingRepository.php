<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\AffiliateBillingRepositoryInterface;
use App\Models\AffiliateBilling;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Prettus\Repository\Eloquent\BaseRepository;

class AffiliateBillingRepository extends BaseRepository implements AffiliateBillingRepositoryInterface
{
    public function model(): string
    {
        return AffiliateBilling::class;
    }

    public function paginateForScope(
        ?array $scopeUserIds,
        array $filters = [],
        int $perPage = 20,
        string $pageName = 'billings_page',
    ): LengthAwarePaginator {
        $query = $this->model->newQuery()->latest('period_end');

        if ($scopeUserIds !== null) {
            $query->whereIn('user_id', $scopeUserIds);
        }

        $this->applyDateFilter($query, $filters);

        if (! empty($filters['billing_status'])) {
            $query->where('status', (string) $filters['billing_status']);
        }

        return $query
            ->paginate($perPage, ['*'], $pageName)
            ->withQueryString();
    }

    public function sumNetAmountByStatuses(?array $scopeUserIds, array $statuses, array $filters = []): float
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

        return (float) $query->sum('net_amount');
    }

    public function sumNetAmountGroupedByStatus(?array $scopeUserIds, array $filters = []): array
    {
        $query = $this->model->newQuery()
            ->selectRaw('LOWER(status) as normalized_status')
            ->selectRaw('COALESCE(SUM(net_amount), 0) as total')
            ->groupByRaw('LOWER(status)');

        if ($scopeUserIds !== null) {
            $query->whereIn('user_id', $scopeUserIds);
        }

        $this->applyDateFilter($query, $filters);

        /** @var \Illuminate\Support\Collection<int,object{normalized_status:string,total:numeric-string|int|float}> $rows */
        $rows = $query->get();

        $result = [];
        foreach ($rows as $row) {
            $status = (string) ($row->normalized_status ?? '');
            if ($status === '') {
                continue;
            }

            $result[$status] = (float) ($row->total ?? 0);
        }

        return $result;
    }

    /**
     * @param  Builder<AffiliateBilling>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDateFilter(Builder $query, array $filters): void
    {
        if (! empty($filters['date_from'])) {
            $query->where('period_end', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('period_end', '<=', $filters['date_to']);
        }
    }
}
