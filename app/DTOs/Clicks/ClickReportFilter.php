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
        public string $sort = 'created_at',
        public string $direction = 'desc',
        public int $page = 1,
        public int $perPage = 20,
    ) {}

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
            sort: $validated['sort'] ?? 'created_at',
            direction: $validated['direction'] ?? 'desc',
            page: isset($validated['page']) ? (int) $validated['page'] : 1,
            perPage: isset($validated['per_page']) ? (int) $validated['per_page'] : 20,
        );
    }
}
