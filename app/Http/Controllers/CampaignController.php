<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Campaigns\StoreCampaignRequest;
use App\Http\Requests\Campaigns\UpdateCampaignRequest;
use App\Models\Campaign;
use App\Services\Campaign\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaignService,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Campaign::class);

        $campaigns = $this->campaignService->listForUser(
            user: $request->user(),
            filters: $request->only(['status', 'search']),
        );

        return Inertia::render('Campaigns/Index', [
            'campaigns' => $campaigns,
            'filters'   => $request->only(['status', 'search']),
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
}
