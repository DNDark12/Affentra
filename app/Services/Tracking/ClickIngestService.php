<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use App\Contracts\Repositories\TrackingLinkRepositoryInterface;
use App\Jobs\Tracking\RecordClickJob;
use App\Models\TrackingLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ClickIngestService
{
    /** Cache TTL for short_code lookup (5 minutes) */
    private const CACHE_TTL = 300;

    public function __construct(
        private readonly TrackingLinkRepositoryInterface $trackingLinkRepository,
    ) {}

    /**
     * Resolve redirect URL and dispatch click recording to queue.
     * Returns the destination URL or null if code not found.
     */
    public function ingest(string $shortCode, Request $request): ?string
    {
        $link = $this->resolveLink($shortCode);

        if ($link === null) {
            return null;
        }

        // Fetch UTMs from Request. Default to empty strings if not present.
        $utmSource   = $request->query('utm_source');
        $utmMedium   = $request->query('utm_medium');
        $utmCampaign = $request->query('utm_campaign');
        $utmTerm     = $request->query('utm_term');
        $utmContent  = $request->query('utm_content');

        // Dispatch click recording to queue — redirect responds immediately
        RecordClickJob::dispatch(
            [
                'tracking_link_id' => $link->id,
                'sub_id'           => $link->sub_id,
                'ip'               => $request->ip(),
                'user_agent'       => mb_substr((string) $request->userAgent(), 0, 1000),
                'referer'          => mb_substr((string) $request->header('Referer', ''), 0, 2048),
                'utm_source'       => is_string($utmSource) ? $utmSource : null,
                'utm_medium'       => is_string($utmMedium) ? $utmMedium : null,
                'utm_campaign'     => is_string($utmCampaign) ? $utmCampaign : null,
                'utm_term'         => is_string($utmTerm) ? $utmTerm : null,
                'utm_content'      => is_string($utmContent) ? $utmContent : null,
            ],
            $link->id,
        );

        return $link->destination_url;
    }

    /**
     * Resolve TrackingLink from short_code using Redis cache.
     */
    private function resolveLink(string $shortCode): ?TrackingLink
    {
        $cacheKey = 'short_code:' . $shortCode;

        /** @var array<string, mixed>|null $cached */
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            // Reconstruct lightweight object from cache
            $link        = new TrackingLink();
            $link->id    = $cached['id'];
            $link->destination_url = $cached['destination_url'];
            $link->sub_id = $cached['sub_id'];

            return $link;
        }

        $link = $this->trackingLinkRepository->findByShortCode($shortCode);

        if ($link !== null) {
            Cache::put($cacheKey, [
                'id'              => $link->id,
                'destination_url' => $link->destination_url,
                'sub_id'          => $link->sub_id,
            ], self::CACHE_TTL);
        }

        return $link;
    }
}
