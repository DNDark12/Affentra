<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Prettus\Repository\Eloquent\BaseRepository;
use Prettus\Repository\Criteria\RequestCriteria;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    public function model(): string
    {
        return User::class;
    }

    public function boot(): void
    {
        $this->pushCriteria(app(RequestCriteria::class));
    }

    public function findByEmail(string $email): ?User
    {
        /** @var User|null */
        return $this->model->newQuery()
            ->where('email', $email)
            ->first();
    }

    /**
     * Get all descendant user IDs using prefix-based materialized path lookup.
     * Uses the user's own `path` as anchor for index-friendly LIKE.
     *
     * @return list<int>
     */
    public function getDescendantIds(int $userId): array
    {
        /** @var User|null $user */
        $user = $this->model->newQuery()->find($userId, ['id', 'path']);

        if ($user === null) {
            return [];
        }

        $prefix = $user->path ?: (string) $user->id;

        return $this->model->newQuery()
            ->where(function ($q) use ($prefix, $userId) {
                $q->where('path', 'LIKE', $prefix . '/%')
                  ->orWhere('parent_id', $userId);
            })
            ->where('status', UserStatus::Active)
            ->pluck('id')
            ->all();
    }

    /**
     * @return list<int>
     */
    public function getDirectChildIds(int $userId): array
    {
        return $this->model->newQuery()
            ->where('parent_id', $userId)
            ->where('status', UserStatus::Active)
            ->pluck('id')
            ->all();
    }

    public function getActiveByRole(UserRole $role)
    {
        return $this->model->newQuery()
            ->where('role', $role)
            ->where('status', UserStatus::Active)
            ->get();
    }

    public function paginatePartnersForManager(User $manager, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $dateFrom = ! empty($filters['date_from']) ? (string) $filters['date_from'] : null;
        $dateTo = ! empty($filters['date_to']) ? (string) $filters['date_to'] : null;

        $query = $this->model->newQuery()
            ->where('role', UserRole::CTV)
            ->withCount('trackingLinks')
            ->withSum(['dailyStats as total_clicks' => function ($relationQuery) use ($dateFrom, $dateTo): void {
                if ($dateFrom !== null) {
                    $relationQuery->whereDate('date', '>=', $dateFrom);
                }
                if ($dateTo !== null) {
                    $relationQuery->whereDate('date', '<=', $dateTo);
                }
            }], 'clicks')
            ->withSum(['dailyStats as total_orders' => function ($relationQuery) use ($dateFrom, $dateTo): void {
                if ($dateFrom !== null) {
                    $relationQuery->whereDate('date', '>=', $dateFrom);
                }
                if ($dateTo !== null) {
                    $relationQuery->whereDate('date', '<=', $dateTo);
                }
            }], 'orders')
            ->withSum(['dailyStats as total_commission' => function ($relationQuery) use ($dateFrom, $dateTo): void {
                if ($dateFrom !== null) {
                    $relationQuery->whereDate('date', '>=', $dateFrom);
                }
                if ($dateTo !== null) {
                    $relationQuery->whereDate('date', '<=', $dateTo);
                }
            }], 'commission')
            ->latest();

        if ($manager->isLeader()) {
            $query->whereIn('id', $manager->getDescendantIds());
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['search'])) {
            $term = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('name', 'LIKE', $term)
                    ->orWhere('email', 'LIKE', $term);
            });
        }

        return $query->paginate($perPage);
    }

    public function partnerSummaryForManager(User $manager, array $filters = []): array
    {
        $query = $this->model->newQuery()
            ->where('role', UserRole::CTV);

        if ($manager->isLeader()) {
            $query->whereIn('id', $this->getDescendantIds($manager->id));
        }

        if (! empty($filters['search'])) {
            $term = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('name', 'LIKE', $term)
                    ->orWhere('email', 'LIKE', $term);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        $dateFrom = ! empty($filters['date_from']) ? (string) $filters['date_from'] : null;
        $dateTo = ! empty($filters['date_to']) ? (string) $filters['date_to'] : null;

        // "New This Month" strictly respects the current calendar month unless filters dictate otherwise
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        $row = (clone $query)
            ->selectRaw('COUNT(*) as total_partners')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as active_partners',
                [UserStatus::Active->value],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN created_at >= ? AND created_at <= ? THEN 1 ELSE 0 END), 0) as new_this_month',
                [$startOfMonth, $endOfMonth],
            );

        if ($dateFrom) {
            $row->where('created_at', '>=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo) {
            $row->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        $result = $row->first();

        return [
            'total_partners' => (int) ($result?->total_partners ?? 0),
            'active_partners' => (int) ($result?->active_partners ?? 0),
            'new_this_month' => (int) ($result?->new_this_month ?? 0),
        ];
    }

    public function createPartnerForManager(User $manager, array $attributes): User
    {
        $password = $attributes['password'] ?? str()->random(12);

        /** @var User */
        return $this->model->newQuery()->create([
            'name'      => $attributes['name'],
            'email'     => $attributes['email'],
            'password'  => Hash::make($password),
            'role'      => UserRole::CTV,
            'status'    => UserStatus::Active,
            'parent_id' => $manager->id,
            'depth'     => $manager->depth + 1,
            'path'      => trim((string) $manager->path . '/' . $manager->id, '/'),
        ]);
    }
}
