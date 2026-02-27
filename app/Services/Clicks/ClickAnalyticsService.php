<?php

declare(strict_types=1);

namespace App\Services\Clicks;

use App\DTOs\Clicks\ClickReportFilter;
use App\Models\Click;
use App\Models\PlatformConnection;
use App\Models\TrackingLink;
use App\Models\User;
use App\Repositories\Clicks\ClickAnalyticsRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class ClickAnalyticsService
{
    public function __construct(
        private readonly ClickAnalyticsRepository $repository
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
     * Replaces previous 'shopee_sync' records within the same time window to guarantee idempotency.
     *
     * @param  list<array<string, mixed>>  $clicks
     * @return array{upserted: int, failed: int}
     */
    public function upsertFromApiSync(PlatformConnection $connection, array $clicks, Carbon $since, Carbon $until): array
    {
        $upserted = 0;
        $failed = 0;

        $subIds = array_filter(array_unique(array_column($clicks, 'sub_id')));
        if (empty($subIds)) {
            return ['upserted' => 0, 'failed' => count($clicks)];
        }

        $links = TrackingLink::with('user.parent')
            ->whereIn('sub_id', $subIds)
            ->get()
            ->keyBy('sub_id');

        $linkIds = $links->pluck('id')->toArray();

        if (! empty($linkIds)) {
            Click::whereIn('tracking_link_id', $linkIds)
                ->where('referer_domain', 'shopee_sync')
                ->whereBetween('created_at', [$since, $until])
                ->delete();
        }

        $inserts = [];

        foreach ($clicks as $c) {
            $subId = $c['sub_id'] ?? null;
            if (! $subId || ! isset($links[$subId])) {
                $failed++;
                continue;
            }

            $link = $links[$subId];
            $user = $link->user;

            if (! $user) {
                $failed++;
                continue;
            }

            $ownerId = null;
            $leaderId = null;
            $ctvUserId = null;

            if ($user->isOwner()) {
                $ownerId = $user->id;
            } elseif ($user->isLeader()) {
                $ownerId = $user->parent_id ?? $user->id;
                $leaderId = $user->id;
            } elseif ($user->isCTV()) {
                $ctvUserId = $user->id;
                $leader = $user->parent;
                if ($leader) {
                    $leaderId = $leader->id;
                    $ownerId = $leader->parent_id ?? $leader->id;
                }
            }

            $amount = $c['amount'] ?? 1;
            $clickTime = $c['click_time'] ?? now();

            for ($i = 0; $i < $amount; $i++) {
                $inserts[] = [
                    'tracking_link_id' => $link->id,
                    'ip'               => null,
                    'user_agent'       => null,
                    'referer'          => null,
                    'referer_domain'   => 'shopee_sync',
                    'device_type'      => null,
                    'fingerprint_hash' => md5($subId . $clickTime->timestamp . $i),
                    'hash_version'     => 1,
                    'is_bot'           => false,
                    'bot_reason'       => null,
                    'owner_id'         => $ownerId,
                    'leader_id'        => $leaderId,
                    'ctv_user_id'      => $ctvUserId,
                    'created_at'       => $clickTime->format('Y-m-d H:i:s'),
                    'sub_id'           => $subId,
                ];
            }
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            Click::insert($chunk);
            $upserted += count($chunk);
        }

        return ['upserted' => $upserted, 'failed' => $failed];
    }
}
