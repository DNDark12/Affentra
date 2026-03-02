<?php

declare(strict_types=1);

namespace App\Http\Resources\Clicks;

use App\Models\Order;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class ClickConversionItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof BackedEnum ? $this->status->value : $this->status;
        $platform = $this->platform instanceof BackedEnum ? $this->platform->value : $this->platform;

        return [
            'id' => (int) $this->id,
            'order_code' => $this->order_code,
            'external_order_id' => $this->external_order_id,
            'sub_id' => $this->sub_id,
            'status' => $status,
            'platform' => $platform,
            'order_amount' => round((float) $this->order_amount, 2),
            'commission' => round((float) $this->commission, 2),
            'ordered_at' => $this->ordered_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
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
