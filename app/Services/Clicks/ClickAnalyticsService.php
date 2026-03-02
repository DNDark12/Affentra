<?php

declare(strict_types=1);

namespace App\Services\Clicks;

use App\Contracts\Repositories\TrackingLinkRepositoryInterface;
use App\DTOs\Clicks\ClickReportFilter;
use App\Models\PlatformConnection;
use App\Models\TrackingLink;
use App\Models\User;
use App\Repositories\Clicks\ClickAnalyticsRepository;
use App\Support\TrackingLinkIdentity;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class ClickAnalyticsService
{
    public function __construct(
        private readonly ClickAnalyticsRepository $repository,
        private readonly TrackingLinkRepositoryInterface $trackingLinkRepository,
    ) {}

    public function getSummary(ClickReportFilter $filter, User $actor): array
    {
        return $this->repository->getSummary($filter, $actor);
    }

    public function getReportData(ClickReportFilter $filter, User $actor): LengthAwarePaginator
    {
        return $this->repository->getReportData($filter, $actor);
    }

    public function getConversionData(ClickReportFilter $filter, User $actor): LengthAwarePaginator
    {
        return $this->repository->getConversionData($filter, $actor);
    }

    /**
     * Upserts synced click records extracted from third-party platforms.
     * Supports unmatched sub_id by storing unattributed rows.
     *
     * @param  list<array<string, mixed>>  $clicks
     * @return array{
     *   upserted: int,
     *   failed: int,
     *   deleted: int,
     *   matched: int,
     *   unattributed: int,
     *   min_date: string|null,
     *   max_date: string|null,
     *   affected_link_ids: list<int>
     * }
     */
    public function upsertFromApiSync(PlatformConnection $connection, array $clicks, Carbon $since, Carbon $until): array
    {
        $subIds = array_values(array_unique(array_values(array_filter(
            array_map(fn (array $row): ?string => $this->normalizeSubId($row['sub_id'] ?? null), $clicks),
        ))));

        $links = $this->repository->resolveLinksBySubIds($subIds);
        $linksByItemId = $this->buildLinksByItemId($connection);

        $deleted = $this->repository->cleanupSyncedClicks(
            connectionId: $connection->id,
            since: $since,
            until: $until,
            source: 'shopee_sync'
        );

        $connection->loadMissing('user.parent');
        $connectionUser = $connection->user;

        $inserts = [];
        $failed = 0;
        $matched = 0;
        $unattributed = 0;

        foreach ($clicks as $row) {
            $subId = $this->normalizeSubId($row['sub_id'] ?? null);

            $link = $subId !== null ? ($links->get($subId) ?: null) : null;
            $resolvedTrackingLinkId = $link?->id;
            $attributionSource = $resolvedTrackingLinkId !== null ? 'sub_id' : 'none';

            if ($resolvedTrackingLinkId === null) {
                $itemId = trim((string) ($row['item_id'] ?? ''));
                if ($itemId !== '' && isset($linksByItemId[$itemId]) && (($linksByItemId[$itemId]['ambiguous'] ?? false) !== true)) {
                    $resolvedLinkId = (int) ($linksByItemId[$itemId]['id'] ?? 0);
                    if ($resolvedLinkId > 0) {
                        $resolvedTrackingLinkId = $resolvedLinkId;
                        $attributionSource = 'item_id';
                    }
                }
            }

            $user = $link?->user ?? $connectionUser;

            if ($user === null) {
                $failed++;
                continue;
            }

            $scope = $this->resolveScopeIds($user);

            $amount = max(1, (int) ($row['amount'] ?? 1));
            $clickTime = $row['click_time'] ?? now();
            if (! $clickTime instanceof Carbon) {
                $clickTime = Carbon::parse((string) $clickTime);
            }

            $attributionStatus = $resolvedTrackingLinkId !== null ? 'matched' : 'unattributed';
            if ($attributionStatus === 'matched') {
                $matched += $amount;
            } else {
                $unattributed += $amount;
            }

            $sourceMeta = [
                'platform' => $row['platform'] ?? $connection->platform,
                'campaign_id' => $row['campaign_id'] ?? null,
                'item_id' => $row['item_id'] ?? null,
                'attribution_status' => $attributionStatus,
                'attribution_source' => $attributionSource,
            ];

            for ($i = 0; $i < $amount; $i++) {
                $seed = ($subId ?? 'na') . '|' . $clickTime->timestamp . '|' . $i . '|' . $connection->id;
                $inserts[] = [
                    'tracking_link_id' => $resolvedTrackingLinkId,
                    'connection_id' => $connection->id,
                    'ip' => null,
                    'user_agent' => null,
                    'referer' => null,
                    'referer_domain' => 'shopee_sync',
                    'device_type' => null,
                    'fingerprint_hash' => hash('sha256', $seed),
                    'hash_version' => 1,
                    'is_bot' => false,
                    'bot_reason' => null,
                    'owner_id' => $scope['owner_id'],
                    'leader_id' => $scope['leader_id'],
                    'ctv_user_id' => $scope['ctv_user_id'],
                    'created_at' => $clickTime->format('Y-m-d H:i:s'),
                    'sub_id' => $subId,
                    'attribution_status' => $attributionStatus,
                    'source_meta' => json_encode($sourceMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        $upserted = $this->repository->bulkInsertSyncedClicks($inserts, 500);
        $summary = $this->repository->summarizeAffectedWindow($inserts);

        if ($summary['affected_link_ids'] !== []) {
            $this->trackingLinkRepository->recomputeClicksCountBulk($summary['affected_link_ids']);
        }

        if ($summary['min_date'] === null && $deleted > 0) {
            $summary['min_date'] = $since->toDateString();
            $summary['max_date'] = $until->toDateString();
        }

        return [
            'upserted' => $upserted,
            'failed' => $failed,
            'deleted' => $deleted,
            'matched' => $matched,
            'unattributed' => $unattributed,
            'min_date' => $summary['min_date'],
            'max_date' => $summary['max_date'],
            'affected_link_ids' => $summary['affected_link_ids'],
        ];
    }

    /**
     * @return array{owner_id: int|null, leader_id: int|null, ctv_user_id: int|null}
     */
    private function resolveScopeIds(User $user): array
    {
        if ($user->isOwner()) {
            return ['owner_id' => $user->id, 'leader_id' => null, 'ctv_user_id' => null];
        }

        if ($user->isLeader()) {
            return [
                'owner_id' => $user->parent_id ?? $user->id,
                'leader_id' => $user->id,
                'ctv_user_id' => null,
            ];
        }

        $leader = $user->parent;
        if ($leader) {
            return [
                'owner_id' => $leader->parent_id ?? $leader->id,
                'leader_id' => $leader->id,
                'ctv_user_id' => $user->id,
            ];
        }

        return [
            'owner_id' => null,
            'leader_id' => null,
            'ctv_user_id' => $user->id,
        ];
    }

    private function normalizeSubId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $subId = trim((string) $value);
        if ($subId === '') {
            return null;
        }

        $normalized = mb_strtolower($subId);
        $invalidTokens = ['-', '--', '---', '----', 'n/a', 'na', 'none', 'null', '(not set)', 'undefined'];
        if (in_array($normalized, $invalidTokens, true)) {
            return null;
        }

        return $subId;
    }

    /**
     * @return array<string, array{id?: int, ambiguous?: bool}>
     */
    private function buildLinksByItemId(PlatformConnection $connection): array
    {
        $map = [];

        $links = TrackingLink::query()
            ->where('platform', $connection->platform)
            ->where('user_id', $connection->user_id)
            ->get(['id', 'destination_url', 'meta']);

        foreach ($links as $link) {
            $productKey = TrackingLinkIdentity::extractShopeeProductKey(
                $link->destination_url,
                is_array($link->meta) ? $link->meta : null
            );
            if ($productKey === null || ! str_contains($productKey, ':')) {
                continue;
            }

            [, $itemId] = explode(':', $productKey, 2);
            $itemId = trim($itemId);
            if ($itemId === '') {
                continue;
            }

            if (isset($map[$itemId]) && (($map[$itemId]['id'] ?? null) !== $link->id)) {
                $map[$itemId] = ['ambiguous' => true];
                continue;
            }

            if (($map[$itemId]['ambiguous'] ?? false) === true) {
                continue;
            }

            $map[$itemId] = ['id' => $link->id];
        }

        return $map;
    }
}
