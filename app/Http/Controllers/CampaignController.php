<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Campaigns\ListCampaignsRequest;
use App\Http\Requests\Campaigns\StoreCampaignRequest;
use App\Http\Requests\Campaigns\UpdateCampaignRequest;
use App\Models\Campaign;
use App\Models\PlatformConnection;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\CampaignSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaignService,
        private readonly CampaignSyncService $campaignSyncService,
    ) {}

    public function index(ListCampaignsRequest $request): Response
    {
        Gate::authorize('viewAny', Campaign::class);

        $filters = $request->filters();

        $campaigns = $this->campaignService->listForUser(
            user: $request->user(),
            filters: $filters,
        );
        $summary = $this->campaignService->globalStatsForUser(
            user: $request->user(),
            filters: $filters,
        );

        $campaignConnections = PlatformConnection::query()
            ->where('user_id', $request->user()->id)
            ->where('platform', 'shopee')
            ->whereIn('method', ['cookie', 'open_api'])
            ->whereIn('status', ['active', 'error'])
            ->orderByDesc('last_campaign_sync_at')
            ->get(['id', 'status', 'last_campaign_sync_at']);

        $lastCampaignSyncAt = $campaignConnections
            ->pluck('last_campaign_sync_at')
            ->filter()
            ->max();

        return Inertia::render('Campaigns/Index', [
            'campaigns' => $campaigns,
            'filters'   => $filters,
            'summary'   => $summary,
            'campaignSync' => [
                'available' => $campaignConnections->isNotEmpty(),
                'connection_count' => $campaignConnections->count(),
                'last_synced_at' => $lastCampaignSyncAt?->toIso8601String(),
            ],
        ]);
    }

    public function store(StoreCampaignRequest $request): JsonResponse
    {
        Gate::authorize('create', Campaign::class);

        $campaign = $this->campaignService->create(
            user: $request->user(),
            validated: $request->validated(),
        );

        return response()->json(['ok' => true, 'data' => $campaign], 201);
    }

    public function update(UpdateCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        Gate::authorize('update', $campaign);

        $campaign = $this->campaignService->update(
            campaign: $campaign,
            validated: $request->validated(),
        );

        return response()->json(['ok' => true, 'data' => $campaign]);
    }

    public function syncFromShopee(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Campaign::class);

        if ($request->user()->isCTV()) {
            return response()->json([
                'ok' => false,
                'message' => 'Unauthorized action.',
            ], 403);
        }

        try {
            $result = $this->campaignSyncService->syncForUser($request->user());
        } catch (\RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $linksProvisioned = (int) ($result['links_provisioned'] ?? 0);
        $message = $linksProvisioned > 0
            ? "Đã đồng bộ campaign từ Shopee và tạo {$linksProvisioned} tracking link tương ứng."
            : 'Đã đồng bộ campaign từ Shopee.';

        return response()->json([
            'ok' => true,
            'data' => $result,
            'message' => $message,
        ]);
    }
}
