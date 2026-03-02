<?php

declare(strict_types=1);

namespace App\Http\Resources\Clicks;

use App\Models\Click;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Click
 */
class ClickReportItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'tracking_link_id' => $this->tracking_link_id !== null ? (int) $this->tracking_link_id : null,
            'connection_id' => $this->connection_id !== null ? (int) $this->connection_id : null,
            'sub_id' => $this->sub_id,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'referer' => $this->referer,
            'referer_domain' => $this->referer_domain,
            'device_type' => $this->device_type,
            'is_bot' => (bool) $this->is_bot,
            'bot_reason' => $this->bot_reason,
            'attribution_status' => $this->attribution_status,
            'source_meta' => $this->source_meta,
            'created_at' => $this->created_at?->toIso8601String(),
            'tracking_link' => $this->trackingLink ? [
                'id' => (int) $this->trackingLink->id,
                'short_code' => $this->trackingLink->short_code,
                'sub_id' => $this->trackingLink->sub_id,
                'campaign' => $this->trackingLink->campaign ? [
                    'id' => (int) $this->trackingLink->campaign->id,
                    'name' => $this->trackingLink->campaign->name,
                ] : null,
            ] : null,
        ];
    }
}
