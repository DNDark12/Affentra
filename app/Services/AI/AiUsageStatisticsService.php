<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\UserRole;
use App\Models\ContentGeneration;
use App\Models\TrackingLink;
use App\Models\User;
use App\Services\Scope\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AiUsageStatisticsService
{
    public function __construct(
        private readonly ScopeResolver $scopeResolver,
    ) {}

    /**
     * @return array{
     *   account: array<string, int|float>,
     *   partner: array<string, int|float>|null,
     *   total_shop: array<string, int|float>,
     *   unknown_shop: array<string, int|float>,
     *   meta: array{has_partner:bool,partner_count:int,total_shop_count:int}
     * }
     */
    public function forActor(User $actor): array
    {
        $partnerIds = $this->resolvePartnerIds($actor);
        $hasPartner = $partnerIds !== [];
        $showPartnerSegment = $actor->isOwner() || $actor->isLeader();

        $account = $this->aggregate(
            ContentGeneration::query()->where('user_id', $actor->id)
        );

        $partner = $hasPartner
            ? $this->aggregate(
                ContentGeneration::query()->whereIn('user_id', $partnerIds)
            )
            : ($showPartnerSegment ? $this->emptyMetrics() : null);

        $totalShop = $this->aggregate(
            ContentGeneration::query()
                ->where('user_id', $actor->id)
                ->whereNotNull('platform_connection_id')
        );

        $totalShopCount = (int) TrackingLink::query()
            ->where('user_id', $actor->id)
            ->whereNotNull('platform_connection_id')
            ->distinct('platform_connection_id')
            ->count('platform_connection_id');

        $unknownShop = $this->aggregate(
            ContentGeneration::query()
                ->where('user_id', $actor->id)
                ->whereNull('platform_connection_id')
        );

        if ((int) ($unknownShop['requests_total'] ?? 0) > 0) {
            Log::info('ai_stats_unknown_shop_nonzero', [
                'actor_id' => $actor->id,
                'requests_total' => (int) $unknownShop['requests_total'],
            ]);
        }

        return [
            'account' => $account,
            'partner' => $partner,
            'total_shop' => $totalShop,
            'unknown_shop' => $unknownShop,
            'meta' => [
                'has_partner' => $showPartnerSegment,
                'partner_count' => count($partnerIds),
                'total_shop_count' => $totalShopCount,
            ],
        ];
    }

    /**
     * @return array{
     *   account: array<string, int|float>,
     *   partner: array<string, int|float>|null,
     *   total_shop: array<string, int|float>,
     *   shop: array<string, int|float>,
     *   unknown_shop: array<string, int|float>,
     *   link: array<string, int|float>,
     *   meta: array{shop_id:int|null,shop_label:string|null,has_partner:bool,partner_count:int,total_shop_count:int}
     * }
     */
    public function forTrackingLink(User $actor, TrackingLink $trackingLink): array
    {
        $overview = $this->forActor($actor);
        $trackingLink->loadMissing('platformConnection:id,label');

        $shopId = $trackingLink->platform_connection_id !== null
            ? (int) $trackingLink->platform_connection_id
            : null;
        $shopLabel = $trackingLink->platformConnection?->label;

        if ($shopId === null) {
            Log::warning('ai_shop_mapping_missing', [
                'actor_id' => $actor->id,
                'tracking_link_id' => $trackingLink->id,
            ]);
        }

        $shop = $shopId !== null
            ? $this->aggregate(
                ContentGeneration::query()
                    ->where('user_id', $actor->id)
                    ->where('platform_connection_id', $shopId)
            )
            : $this->emptyMetrics();

        $link = $this->aggregate(
            ContentGeneration::query()->where('tracking_link_id', $trackingLink->id)
        );

        Log::info('ai_stats_segment_computed', [
            'actor_id' => $actor->id,
            'tracking_link_id' => $trackingLink->id,
            'shop_id' => $shopId,
            'has_partner' => $overview['meta']['has_partner'],
            'partner_count' => $overview['meta']['partner_count'],
            'account_requests' => (int) ($overview['account']['requests_total'] ?? 0),
            'shop_requests' => (int) ($shop['requests_total'] ?? 0),
            'link_requests' => (int) $link['requests_total'],
        ]);

        return [
            ...$overview,
            'shop' => $shop,
            'link' => $link,
            'meta' => [
                ...$overview['meta'],
                'shop_id' => $shopId,
                'shop_label' => $shopLabel,
            ],
        ];
    }

    /**
     * @return list<int>
     */
    private function resolvePartnerIds(User $actor): array
    {
        if ($actor->isPartner()) {
            return [];
        }

        $visibleUserIds = $this->scopeResolver->resolveVisibleUserIds($actor);

        $query = User::query()
            ->where('role', UserRole::Partner->value)
            ->where('id', '!=', $actor->id);

        if ($visibleUserIds !== null) {
            $query->whereIn('id', $visibleUserIds);
        }

        return $query
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<string, int|float>
     */
    private function aggregate(Builder $query): array
    {
        $jsonLengthExpr = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            ? "JSON_LENGTH(output_payload, '$.media')"
            : "COALESCE(json_array_length(json_extract(output_payload, '$.media')), 0)";

        $stats = $query->selectRaw("
            COUNT(*) as requests_total,
            SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) as requests_succeeded,
            SUM(CASE WHEN status IN ('failed', 'canceled') THEN 1 ELSE 0 END) as requests_failed,
            SUM(CASE WHEN from_cache = 1 THEN 1 ELSE 0 END) as cache_hits,
            SUM(CASE WHEN status = 'succeeded' THEN (COALESCE(tokens_prompt, 0) + COALESCE(tokens_completion, 0)) ELSE 0 END) as total_tokens,
            SUM(CASE WHEN type = 'image' AND status = 'succeeded' THEN {$jsonLengthExpr} ELSE 0 END) as total_images,
            SUM(CASE WHEN type = 'video' AND status = 'succeeded' THEN {$jsonLengthExpr} ELSE 0 END) as total_videos,
            SUM(CASE WHEN status = 'succeeded' THEN COALESCE(cost_amount, 0) ELSE 0 END) as total_cost_amount
        ")->first();

        return [
            'requests_total' => (int) ($stats->requests_total ?? 0),
            'requests_succeeded' => (int) ($stats->requests_succeeded ?? 0),
            'requests_failed' => (int) ($stats->requests_failed ?? 0),
            'cache_hits' => (int) ($stats->cache_hits ?? 0),
            'tokens' => (int) ($stats->total_tokens ?? 0),
            'images' => (int) ($stats->total_images ?? 0),
            'videos' => (int) ($stats->total_videos ?? 0),
            'cost_amount' => round((float) ($stats->total_cost_amount ?? 0), 6),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function emptyMetrics(): array
    {
        return [
            'requests_total' => 0,
            'requests_succeeded' => 0,
            'requests_failed' => 0,
            'cache_hits' => 0,
            'tokens' => 0,
            'images' => 0,
            'videos' => 0,
            'cost_amount' => 0.0,
        ];
    }
}
