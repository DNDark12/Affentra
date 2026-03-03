<?php

declare(strict_types=1);

namespace App\Services\Campaign;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\PlatformConnection;
use App\Models\TrackingLink;
use App\Models\User;
use App\Services\Integration\IntegrationFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class CampaignSyncService
{
    /**
     * Sync Shopee campaigns for all eligible active connections.
     *
     * @return array{connections: int, fetched: int, upserted: int, links_provisioned: int}
     */
    public function syncDaily(): array
    {
        $connections = PlatformConnection::query()
            ->where('platform', 'shopee')
            ->whereIn('method', ['cookie', 'open_api'])
            ->where('status', 'active')
            ->get();

        return $this->syncMany($connections);
    }

    /**
     * Sync Shopee campaigns for the current user's own connections.
     *
     * @return array{connections: int, fetched: int, upserted: int, links_provisioned: int}
     */
    public function syncForUser(User $user): array
    {
        $connections = PlatformConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', 'shopee')
            ->whereIn('method', ['cookie', 'open_api'])
            ->whereIn('status', ['active', 'error'])
            ->get();

        if ($connections->isEmpty()) {
            throw new RuntimeException('Chưa có kết nối Shopee khả dụng để đồng bộ campaign.');
        }

        return $this->syncMany($connections);
    }

    /**
     * Sync Shopee campaigns for a single connection.
     *
     * @return array{connections: int, fetched: int, upserted: int, links_provisioned: int}
     */
    public function syncForConnection(PlatformConnection $connection): array
    {
        if (
            $connection->platform !== 'shopee'
            || ! in_array($connection->method, ['cookie', 'open_api'], true)
            || ! in_array($connection->status, ['active', 'error'], true)
        ) {
            throw new RuntimeException('Connection is not eligible for campaign sync.');
        }

        return $this->syncMany(collect([$connection]));
    }

    /**
     * @param  Collection<int, PlatformConnection>  $connections
     * @return array{connections: int, fetched: int, upserted: int, links_provisioned: int}
     */
    private function syncMany(Collection $connections): array
    {
        $totalFetched = 0;
        $totalUpserted = 0;
        $totalLinksProvisioned = 0;
        $processed = 0;

        foreach ($connections as $connection) {
            if (! IntegrationFactory::supports($connection->platform)) {
                continue;
            }

            $adapter = IntegrationFactory::make($connection->platform);
            if (! method_exists($adapter, 'fetchCampaigns')) {
                continue;
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = $adapter->fetchCampaigns($connection);
            $totalFetched += count($rows);
            $processed++;

            $now = now();
            $payload = [];

            Log::debug('ShopeeIntegration: fetched campaign rows', ['count' => count($rows), 'first' => $rows[0] ?? null]);
            foreach ($rows as $row) {
                Log::debug('CampaignSyncService: mapping row', ['row' => $row]);
                $mapped = $this->mapShopeeCampaign($connection, $row, $now);
                Log::debug('CampaignSyncService: mapped result', ['mapped' => $mapped]);
                if ($mapped !== null) {
                    $payload[] = $mapped;
                }
            }

            if ($payload !== []) {
                Campaign::query()->upsert(
                    $payload,
                    ['user_id', 'platform', 'external_id'],
                    [
                        'name',
                        'source',
                        'status',
                        'external_status',
                        'date_start',
                        'date_end',
                        'description',
                        'campaign_url',
                        'impressions',
                        'clicks',
                        'banner_image_id',
                        'synced_at',
                        'source_meta',
                        'updated_at',
                    ]
                );

            }

            $externalIdsForProvision = Campaign::query()
                ->where('user_id', $connection->user_id)
                ->where('platform', 'shopee')
                ->where(static function ($query): void {
                    $query->where('impressions', '>', 0)
                        ->orWhere('clicks', '>', 0);
                })
                ->pluck('external_id')
                ->filter(static fn ($id): bool => is_string($id) && trim($id) !== '')
                ->map(static fn (string $id): string => trim($id))
                ->values()
                ->all();

            $totalLinksProvisioned += $this->provisionTrackingLinksForCampaigns(
                connection: $connection,
                externalIds: $externalIdsForProvision,
            );

            $totalUpserted += count($payload);
            $connection->update(['last_campaign_sync_at' => $now]);
        }

        return [
            'connections' => $processed,
            'fetched' => $totalFetched,
            'upserted' => $totalUpserted,
            'links_provisioned' => $totalLinksProvisioned,
        ];
    }

    /**
     * @param  list<string>  $externalIds
     */
    private function provisionTrackingLinksForCampaigns(PlatformConnection $connection, array $externalIds): int
    {
        if ($externalIds === []) {
            return 0;
        }

        $campaigns = Campaign::query()
            ->where('user_id', $connection->user_id)
            ->where('platform', 'shopee')
            ->whereIn('external_id', $externalIds)
            ->get(['id', 'external_id', 'campaign_url', 'impressions', 'clicks', 'name']);

        $provisioned = 0;
        foreach ($campaigns as $campaign) {
            if ((int) $campaign->impressions <= 0 && (int) $campaign->clicks <= 0) {
                continue;
            }

            $hasAssignedLink = TrackingLink::query()
                ->where('campaign_id', $campaign->id)
                ->where('user_id', $connection->user_id)
                ->where('platform', 'shopee')
                ->where('status', '!=', 'archived')
                ->exists();
            if ($hasAssignedLink) {
                continue;
            }

            $destinationUrl = $this->resolveCampaignDestinationUrl($campaign);
            if ($destinationUrl === null) {
                continue;
            }

            $unassignedLink = TrackingLink::query()
                ->where('user_id', $connection->user_id)
                ->where('platform', 'shopee')
                ->whereNull('campaign_id')
                ->where('destination_url', $destinationUrl)
                ->where('status', '!=', 'archived')
                ->first();

            if ($unassignedLink !== null) {
                $meta = is_array($unassignedLink->meta) ? $unassignedLink->meta : [];
                $meta['auto_provisioned_campaign'] = true;
                $meta['campaign_external_id'] = $campaign->external_id;
                $meta['campaign_name'] = $campaign->name;

                $unassignedLink->update([
                    'campaign_id' => $campaign->id,
                    'source' => $unassignedLink->source ?: 'campaign_sync_auto',
                    'channel' => $unassignedLink->channel ?: 'campaign',
                    'meta' => $meta,
                ]);
                $provisioned++;
                continue;
            }

            $link = new TrackingLink();
            $link->fill([
                'user_id' => $connection->user_id,
                'campaign_id' => $campaign->id,
                'short_code' => $this->generateUniqueShortCode(),
                'destination_url' => $destinationUrl,
                'platform' => 'shopee',
                'source' => 'campaign_sync_auto',
                'channel' => 'campaign',
                'status' => 'active',
                'meta' => [
                    'auto_provisioned_campaign' => true,
                    'campaign_external_id' => $campaign->external_id,
                    'campaign_name' => $campaign->name,
                ],
            ]);
            $link->save();
            $provisioned++;
        }

        return $provisioned;
    }

    private function resolveCampaignDestinationUrl(Campaign $campaign): ?string
    {
        $campaignUrl = trim((string) ($campaign->campaign_url ?? ''));
        if ($campaignUrl !== '') {
            return $campaignUrl;
        }

        $externalId = trim((string) ($campaign->external_id ?? ''));
        if ($externalId === '') {
            return null;
        }

        return "https://affiliate.shopee.vn/campaign/campaign_list?campaign_id={$externalId}";
    }

    private function generateUniqueShortCode(): string
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $candidate = Str::lower(Str::random(8));
            $exists = TrackingLink::query()
                ->where('short_code', $candidate)
                ->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique short code for campaign tracking link.');
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Carbon  $now
     * @return array<string, mixed>|null
     */
    private function mapShopeeCampaign(PlatformConnection $connection, array $row, Carbon $now): ?array
    {
        $externalId = trim((string) ($row['campaignId'] ?? $row['campaign_id'] ?? $row['id'] ?? ''));
        if ($externalId === '') {
            Log::warning('CampaignSyncService: Missing external ID for campaign row.', ['row' => $row]);
            return null;
        }

        $name = trim((string) ($row['campaignName'] ?? $row['campaign_name'] ?? $row['name'] ?? ''));
        if ($name === '') {
            $name = 'Shopee Campaign #' . $externalId;
        }

        $startTime = $row['campaignStartTime'] ?? $row['campaign_start_time'] ?? $row['start_time'] ?? null;
        $endTime = $row['campaignEndTime'] ?? $row['campaign_end_time'] ?? $row['end_time'] ?? null;

        $dateStart = $this->asCarbon($startTime)?->toDateString();
        $dateEnd = $this->asCarbon($endTime)?->toDateString();
        $externalStatus = $row['campaignStatus'] ?? $row['campaign_status'] ?? $row['status'] ?? null;

        return [
            'user_id' => $connection->user_id,
            'external_id' => $externalId,
            'name' => $name,
            'platform' => 'shopee',
            'source' => 'shopee_sync',
            'status' => $this->mapStatus($externalStatus, $dateStart, $dateEnd)->value,
            'external_status' => $externalStatus !== null ? (string) $externalStatus : null,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'description' => $this->nullableString($row['campaignDescription'] ?? $row['campaign_description'] ?? $row['description'] ?? null),
            'campaign_url' => $this->nullableString($row['campaignUrl'] ?? $row['campaign_url'] ?? $row['url'] ?? null),
            'impressions' => (int) ($row['campaignImpressionNum'] ?? $row['campaign_impression_num'] ?? $row['impressions'] ?? 0),
            'clicks' => (int) ($row['campaignClickNum'] ?? $row['campaign_click_num'] ?? $row['clicks'] ?? 0),
            'banner_image_id' => $this->nullableString($row['bannerImageId'] ?? $row['banner_image_id'] ?? $row['image_id'] ?? null),
            'synced_at' => $now,
            'source_meta' => json_encode([
                'raw' => $row,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function mapStatus(mixed $externalStatus, ?string $dateStart, ?string $dateEnd): CampaignStatus
    {
        if ($externalStatus !== null) {
            if (is_numeric($externalStatus)) {
                return match ((int) $externalStatus) {
                    1 => CampaignStatus::Active,
                    2 => CampaignStatus::Paused,
                    3 => CampaignStatus::Ended,
                    default => $this->mapStatusByDate($dateStart, $dateEnd),
                };
            }

            $status = mb_strtolower(trim((string) $externalStatus));
            if ($status !== '') {
                if (
                    str_contains($status, 'active')
                    || str_contains($status, 'running')
                    || str_contains($status, 'ongoing')
                    || str_contains($status, 'đang')
                ) {
                    return CampaignStatus::Active;
                }

                if (
                    str_contains($status, 'pause')
                    || str_contains($status, 'suspend')
                    || str_contains($status, 'tạm dừng')
                ) {
                    return CampaignStatus::Paused;
                }

                if (
                    str_contains($status, 'end')
                    || str_contains($status, 'close')
                    || str_contains($status, 'expire')
                    || str_contains($status, 'hết hạn')
                ) {
                    return CampaignStatus::Ended;
                }
            }
        }

        return $this->mapStatusByDate($dateStart, $dateEnd);
    }

    private function mapStatusByDate(?string $dateStart, ?string $dateEnd): CampaignStatus
    {
        $today = now()->toDateString();

        if ($dateEnd !== null && $dateEnd < $today) {
            return CampaignStatus::Ended;
        }

        if ($dateStart !== null && $dateStart > $today) {
            return CampaignStatus::Paused;
        }

        return CampaignStatus::Active;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private function asCarbon(mixed $value): ?Carbon
    {
        if (! is_numeric($value)) {
            return null;
        }

        $timestamp = (int) $value;
        if ($timestamp <= 0) {
            return null;
        }

        if ($timestamp > 10_000_000_000) {
            $timestamp = (int) floor($timestamp / 1000);
        }

        return Carbon::createFromTimestamp($timestamp);
    }
}
