<?php

declare(strict_types=1);

namespace App\Http\Resources\Clicks;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin array<string, mixed>
 */
class ClickSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $funnel = is_array($this['funnel'] ?? null) ? $this['funnel'] : [];
        $totals = is_array($this['totals'] ?? null) ? $this['totals'] : [];
        $trendByDay = is_array($this['trend_by_day'] ?? null) ? $this['trend_by_day'] : [];
        $campaignBreakdown = is_array($this['campaign_breakdown'] ?? null) ? $this['campaign_breakdown'] : [];

        return [
            'funnel' => [
                'clicks' => (int) ($funnel['clicks'] ?? 0),
                'orders' => (int) ($funnel['orders'] ?? 0),
                'approved' => (int) ($funnel['approved'] ?? 0),
            ],
            'totals' => [
                'clicks' => (int) ($totals['clicks'] ?? 0),
                'unique_clicks' => (int) ($totals['unique_clicks'] ?? 0),
                'valid_clicks' => (int) ($totals['valid_clicks'] ?? 0),
                'bot_clicks' => (int) ($totals['bot_clicks'] ?? 0),
                'orders' => (int) ($totals['orders'] ?? 0),
                'approved' => (int) ($totals['approved'] ?? 0),
                'commission' => round((float) ($totals['commission'] ?? 0), 2),
            ],
            'cvr' => round((float) ($this['cvr'] ?? 0), 2),
            'approved_rate' => round((float) ($this['approved_rate'] ?? 0), 2),
            'epc' => round((float) ($this['epc'] ?? 0), 2),
            'trend_by_day' => $trendByDay,
            'campaign_breakdown' => $campaignBreakdown,
        ];
    }
}
