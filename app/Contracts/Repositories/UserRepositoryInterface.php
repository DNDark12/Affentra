<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Prettus\Repository\Contracts\RepositoryInterface;

interface UserRepositoryInterface extends RepositoryInterface
{
    /**
     * @return User|null
     */
    public function findByEmail(string $email): ?User;

    /**
     * Get all descendants of a user via materialized path.
     *
     * @return list<int>
     */
    public function getDescendantIds(int $userId): array;

    /**
     * Get IDs of direct children (parent_id = $userId) with active status.
     *
     * @return list<int>
     */
    public function getDirectChildIds(int $userId): array;

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function getActiveByRole(UserRole $role);

    /**
     * Paginate CTV partners visible to manager (owner: all CTV, leader: direct children only).
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginatePartnersForManager(User $manager, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * Create a partner under the given manager.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createPartnerForManager(User $manager, array $attributes): User;
}
