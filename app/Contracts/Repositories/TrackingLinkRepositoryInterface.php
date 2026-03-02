<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Enums\LinkStatus;
use App\Models\TrackingLink;
use Prettus\Repository\Contracts\RepositoryInterface;

interface TrackingLinkRepositoryInterface extends RepositoryInterface
{
    /**
     * Find link by its short_code (used in redirect).
     *
     * @return TrackingLink|null
     */
    public function findByShortCode(string $shortCode): ?TrackingLink;

    /**
     * Check if a short code already exists across all statuses.
     */
    public function existsByShortCode(string $shortCode): bool;

    /**
     * Increment the click counter atomically.
     */
    public function incrementClicksCount(int $linkId): void;

    /**
     * Paginated list scoped to role visibility with optional filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForScope(?array $scopeUserIds, array $filters = []): \Illuminate\Pagination\LengthAwarePaginator;

    /**
     * Find a link by ID within role scope.
     *
     * @param  list<int>|null  $scopeUserIds
     */
    public function findByIdForScope(int $id, ?array $scopeUserIds): ?TrackingLink;

    /**
     * Aggregate lightweight counters for current filter scope.
     *
     * @param  list<int>|null  $scopeUserIds
     * @param  array<string, mixed>  $filters
     * @return array{total_clicks:int,total_orders:int,total_commission:float,total_approved:int}
     */
    public function aggregateCountersForScope(?array $scopeUserIds, array $filters = []): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function createLink(array $data): TrackingLink;

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateLink(int $id, array $data): TrackingLink;

    /**
     * Validate allowed status transition.
     */
    public function assertStatusTransition(LinkStatus $from, LinkStatus $to): void;

    /**
     * Recompute clicks_count for specific tracking links in bulk.
     *
     * @param  list<int>  $linkIds
     * @return int affected rows
     */
    public function recomputeClicksCountBulk(array $linkIds): int;
}
