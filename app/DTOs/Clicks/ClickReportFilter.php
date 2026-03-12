<?php

declare(strict_types=1);

namespace App\DTOs\Clicks;

use Carbon\Carbon;

readonly class ClickReportFilter
{
    public function __construct(
        public ?Carbon $dateFrom = null,
        public ?Carbon $dateTo = null,
        public ?int $trackingLinkId = null,
        public ?int $campaignId = null,
        public ?string $deviceType = null,
        public ?string $refererDomain = null,
        public ?bool $isBot = null,
        public ?string $searchQuery = null,
        public ?array $utmSources = null,
        public ?array $utmCampaigns = null,
        public ?array $devices = null,
        public string $groupBy = 'date',
        public string $sort = 'created_at',
        public string $direction = 'desc',
        public int $page = 1,
        public int $perPage = 20,
    ) {}

    /**
     * @return array<string>
     */
    public static function getAllowedGroupBys(): array
    {
        return ['date', 'tracking_link_id', 'utm_source', 'utm_campaign', 'device_type'];
    }

    /**
     * @param array<string, mixed> $validated
     */
    public static function fromRequest(array $validated): self
    {
        return new self(
            dateFrom: isset($validated['date_from']) ? Carbon::parse($validated['date_from'])->startOfDay() : null,
            dateTo: isset($validated['date_to']) ? Carbon::parse($validated['date_to'])->endOfDay() : null,
            trackingLinkId: isset($validated['tracking_link_id']) ? (int) $validated['tracking_link_id'] : null,
            campaignId: isset($validated['campaign_id']) ? (int) $validated['campaign_id'] : null,
            deviceType: $validated['device_type'] ?? null,
            refererDomain: $validated['referer_domain'] ?? null,
            isBot: isset($validated['is_bot']) ? (bool) $validated['is_bot'] : null,
            searchQuery: $validated['q'] ?? null,
            utmSources: isset($validated['utm_sources']) && is_array($validated['utm_sources']) ? $validated['utm_sources'] : null,
            utmCampaigns: isset($validated['utm_campaigns']) && is_array($validated['utm_campaigns']) ? $validated['utm_campaigns'] : null,
            devices: isset($validated['devices']) && is_array($validated['devices']) ? $validated['devices'] : null,
            groupBy: isset($validated['group_by']) && in_array($validated['group_by'], self::getAllowedGroupBys(), true) 
                ? $validated['group_by'] 
                : 'date',
            sort: $validated['sort'] ?? 'created_at',
            direction: $validated['direction'] ?? 'desc',
            page: isset($validated['page']) ? (int) $validated['page'] : 1,
            perPage: isset($validated['per_page']) ? (int) $validated['per_page'] : 20,
        );
    }
}
