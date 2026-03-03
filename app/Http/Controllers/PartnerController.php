<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Partners\ListPartnersRequest;
use App\Http\Requests\Partners\StorePartnerRequest;
use App\Models\User;
use App\Services\Partner\PartnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PartnerController extends Controller
{
    public function __construct(
        private readonly PartnerService $partnerService,
    ) {}

    public function index(ListPartnersRequest $request): Response
    {
        /** @var User $auth */
        $auth = $request->user();

        Gate::authorize('viewAny', User::class);

        $filters = $request->filters();

        $partnerData = $this->partnerService
            ->listForManager($auth, $filters)
            ->through(function (User $u) {
                return [
                    'id'                    => $u->id,
                    'name'                  => $u->name,
                    'email'                 => $u->email,
                    'tracking_links_count'  => $u->tracking_links_count ?? 0,
                    'total_clicks'          => (int) ($u->total_clicks ?? 0),
                    'total_orders'          => (int) ($u->total_orders ?? 0),
                    'total_commission'      => (float) ($u->total_commission ?? 0),
                    'status'                => $u->status,
                ];
            });
        $summary = $this->partnerService->summaryForManager($auth, $filters);

        return Inertia::render('Partners/Index', [
            'partners' => $partnerData,
            'summary'  => $summary,
            'filters'  => $filters,
        ]);
    }

    public function store(StorePartnerRequest $request): JsonResponse
    {
        /** @var User $auth */
        $auth = $request->user();

        $partner = $this->partnerService->create($auth, $request->validated());

        return response()->json(['ok' => true, 'data' => $partner], 201);
    }

    public function show(Request $request, User $partner): Response
    {
        /** @var User $auth */
        $auth = $request->user();

        Gate::authorize('viewAny', User::class);

        $payload = $this->partnerService->detailForManager($auth, $partner);

        return Inertia::render('Partners/Show', [
            'partner' => $payload['partner'],
            'overview' => $payload['overview'],
            'financeSummary' => $payload['finance_summary'],
            'recentOrders' => $payload['recent_orders'],
            'recentBillings' => $payload['recent_billings'],
            'recentPayouts' => $payload['recent_payouts'],
            'activity' => $payload['activity'],
        ]);
    }
}
